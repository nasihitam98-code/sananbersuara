<?php

namespace Tests\Feature;

use App\Enums\ElectionStatus;
use App\Enums\RestoreReason;
use App\Enums\VoteStatus;
use App\Enums\WaveKind;
use App\Filament\Pages\VoteDetailPage;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\User;
use App\Models\Vote;
use App\Services\AuditLogger;
use App\Services\Results\DataRetention;
use App\Services\Voting\AttendeeRegistrar;
use App\Services\Voting\BallotBox;
use App\Services\Voting\ElectionLifecycle;
use App\Services\Voting\ResultsCalculator;
use App\Services\Voting\VoterRightRestorer;
use App\Services\Voting\VotingException;
use App\Services\Voting\WaveManager;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class DadakanVotingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Election $election;

    private Ballot $ballot;

    /** @var array<int, Candidate> */
    private array $candidates;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->election = Election::factory()->create();
        $this->ballot = Ballot::factory()->for($this->election)->create();
        $this->candidates = [
            Candidate::factory()->for($this->ballot)->create(['number' => 1]),
            Candidate::factory()->for($this->ballot)->create(['number' => 2]),
            Candidate::factory()->for($this->ballot)->create(['number' => 3]),
        ];

        app(ElectionLifecycle::class)->markReady($this->election, $this->admin);
    }

    /**
     * @return array{attendee: Attendee, pin: string}
     */
    private function register(string $name = 'Budi Santoso'): array
    {
        return app(AttendeeRegistrar::class)->register($this->election, $name, null, $this->admin);
    }

    private function startAndOpenWave(WaveKind $kind = WaveKind::Terbuka, ?int $minutes = 5): void
    {
        app(ElectionLifecycle::class)->start($this->election, $this->admin);
        app(WaveManager::class)->open($this->election, $kind, $minutes, $this->admin);
    }

    public function test_pin_is_stored_only_as_hash(): void
    {
        ['attendee' => $attendee, 'pin' => $pin] = $this->register();

        $this->assertMatchesRegularExpression('/^\d{4}$/', $pin);
        $this->assertNotSame($pin, $attendee->pin_hash);
        $this->assertSame(64, strlen($attendee->pin_hash));
        $this->assertStringNotContainsString($pin, (string) json_encode($attendee->fresh()->toArray()));
    }

    public function test_search_and_pin_are_rejected_before_wave_opens(): void
    {
        ['attendee' => $attendee, 'pin' => $pin] = $this->register();
        app(ElectionLifecycle::class)->start($this->election, $this->admin);

        $this->expectExceptionObject(VotingException::notOpen());
        app(BallotBox::class)->verifyPin($this->election, $attendee, $pin);
    }

    public function test_attendee_can_vote_once_and_second_attempt_is_rejected(): void
    {
        ['attendee' => $attendee, 'pin' => $pin] = $this->register();
        $this->startAndOpenWave();
        $box = app(BallotBox::class);

        $wave = $box->verifyPin($this->election, $attendee, $pin);
        $box->cast($this->election, $attendee, $wave, $this->ballot, $this->candidates[1]);

        try {
            $box->cast($this->election, $attendee, $wave, $this->ballot, $this->candidates[0]);
            $this->fail('Suara kedua seharusnya ditolak.');
        } catch (VotingException $exception) {
            $this->assertSame(VotingException::ALREADY_VOTED, $exception->reason);
        }

        $this->assertSame(1, Vote::query()->count());
        $this->assertSame($this->candidates[1]->id, Vote::query()->first()->candidate_id);
    }

    public function test_voted_attendee_is_hidden_from_search_and_cannot_reenter_pin(): void
    {
        ['attendee' => $attendee, 'pin' => $pin] = $this->register('Siti Aminah');
        $this->register('Siti Rahma');
        $this->startAndOpenWave();
        $box = app(BallotBox::class);

        $this->assertCount(2, $box->search($this->election, 'siti'));

        $wave = $box->verifyPin($this->election, $attendee, $pin);
        $box->cast($this->election, $attendee, $wave, $this->ballot, $this->candidates[0]);

        $this->assertSame(['Siti Rahma'], $box->search($this->election, 'siti')->pluck('name')->all());

        $this->expectExceptionObject(VotingException::alreadyVoted());
        $box->verifyPin($this->election, $attendee, $pin);
    }

    public function test_search_requires_three_characters_and_returns_at_most_five(): void
    {
        foreach (range(1, 8) as $index) {
            $this->register("Ahmad Warga {$index}");
        }

        $this->startAndOpenWave();
        $box = app(BallotBox::class);

        $this->assertCount(0, $box->search($this->election, 'ah'));
        $this->assertCount(5, $box->search($this->election, 'ahmad'));
    }

    public function test_three_wrong_pins_lock_the_name_even_for_the_correct_pin(): void
    {
        ['attendee' => $attendee, 'pin' => $pin] = $this->register();
        $this->startAndOpenWave();
        $box = app(BallotBox::class);
        $wrong = $pin === '9998' ? '9997' : '9998';

        foreach ([2, 1] as $left) {
            try {
                $box->verifyPin($this->election, $attendee, $wrong);
            } catch (VotingException $exception) {
                $this->assertSame(VotingException::PIN_WRONG, $exception->reason);
                $this->assertStringContainsString((string) $left, $exception->getMessage());
            }
        }

        try {
            $box->verifyPin($this->election, $attendee, $wrong);
        } catch (VotingException $exception) {
            $this->assertSame(VotingException::PIN_LOCKED, $exception->reason);
        }

        $this->expectExceptionObject(VotingException::pinLocked());
        $box->verifyPin($this->election, $attendee, $pin);
    }

    public function test_vote_after_deadline_plus_grace_is_rejected_but_within_grace_is_accepted(): void
    {
        ['attendee' => $early, 'pin' => $earlyPin] = $this->register('Pemilih Tepat');
        ['attendee' => $late, 'pin' => $latePin] = $this->register('Pemilih Telat');
        $this->startAndOpenWave(minutes: 1);
        $box = app(BallotBox::class);

        $earlyWave = $box->verifyPin($this->election, $early, $earlyPin);
        $lateWave = $box->verifyPin($this->election, $late, $latePin);

        $this->travel(65)->seconds();
        $box->cast($this->election, $early, $earlyWave, $this->ballot, $this->candidates[0]);

        $this->travel(30)->seconds();
        $this->expectExceptionObject(VotingException::timeUp());
        $box->cast($this->election, $late, $lateWave, $this->ballot, $this->candidates[0]);
    }

    public function test_attendee_registered_while_wave_is_open_can_vote_in_that_same_wave(): void
    {
        $this->register('Datang Awal');
        $this->startAndOpenWave();

        ['attendee' => $newcomer, 'pin' => $pin] = $this->register('Baru Datang');
        $this->assertTrue($newcomer->is_late);

        $box = app(BallotBox::class);
        $box->cast($this->election, $newcomer, $box->verifyPin($this->election, $newcomer, $pin), $this->ballot, $this->candidates[0]);

        $this->assertSame(1, Vote::query()->where('status', VoteStatus::Sah)->count());
    }

    public function test_choice_from_another_ballot_is_rejected(): void
    {
        ['attendee' => $attendee, 'pin' => $pin] = $this->register();
        $otherCandidate = Candidate::factory()->create();
        $this->startAndOpenWave();
        $box = app(BallotBox::class);
        $wave = $box->verifyPin($this->election, $attendee, $pin);

        $this->expectExceptionObject(VotingException::invalidChoice());
        $box->cast($this->election, $attendee, $wave, $this->ballot, $otherCandidate);
    }

    public function test_restore_cancels_old_vote_issues_new_pin_and_allows_revoting(): void
    {
        ['attendee' => $attendee, 'pin' => $oldPin] = $this->register();
        $this->startAndOpenWave();
        $box = app(BallotBox::class);
        $wave = $box->verifyPin($this->election, $attendee, $oldPin);
        $box->cast($this->election, $attendee, $wave, $this->ballot, $this->candidates[0]);

        $result = app(VoterRightRestorer::class)->restore($this->election, $attendee, RestoreReason::NamaDipakaiOrangLain, null, $this->admin);

        $this->assertSame(1, $result['cancelled_votes']);
        $this->assertNotSame($oldPin, $result['pin']);
        $this->assertSame(1, Vote::query()->where('status', VoteStatus::Dibatalkan)->count());
        $this->assertSame(0, Vote::query()->where('status', VoteStatus::Sah)->count());

        $newWave = $box->verifyPin($this->election, $attendee->fresh(), $result['pin']);
        $box->cast($this->election, $attendee, $newWave, $this->ballot, $this->candidates[2]);

        $tally = app(ResultsCalculator::class)->tally($this->ballot, $this->election->currentRound());
        $this->assertSame(1, $tally['valid']);
        $this->assertSame(1, $tally['cancelled']);
        $this->assertSame($this->candidates[2]->id, $tally['candidates'][0]['candidate']->id);
    }

    public function test_restore_audit_entry_does_not_reveal_the_cancelled_choice(): void
    {
        ['attendee' => $attendee, 'pin' => $pin] = $this->register();
        $this->startAndOpenWave();
        $box = app(BallotBox::class);
        $box->cast($this->election, $attendee, $box->verifyPin($this->election, $attendee, $pin), $this->ballot, $this->candidates[1]);

        app(VoterRightRestorer::class)->restore($this->election, $attendee, RestoreReason::GangguanTeknis, null, $this->admin);

        $entries = DB::table('audit_logs')->get()->map(fn (object $row): string => (string) json_encode($row))->implode("\n");
        $meta = DB::table('audit_logs')->pluck('meta')->implode(' ');

        $this->assertStringNotContainsString($this->candidates[1]->name, $entries);
        $this->assertStringNotContainsString('candidate', $meta);
        $this->assertStringNotContainsString($pin, $meta);
    }

    public function test_super_admin_can_see_who_voted_whom_after_close_and_it_is_audited(): void
    {
        $this->startAndOpenWave();
        $box = app(BallotBox::class);
        ['attendee' => $budi, 'pin' => $budiPin] = $this->register('Budi Santoso');
        ['attendee' => $siti, 'pin' => $sitiPin] = $this->register('Siti Aminah');
        $box->cast($this->election, $budi, $box->verifyPin($this->election, $budi, $budiPin), $this->ballot, $this->candidates[0]);
        $box->cast($this->election, $siti, $box->verifyPin($this->election, $siti, $sitiPin), $this->ballot, $this->candidates[1]);

        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel('admin');
        $superAdmin = User::factory()->create();
        $superAdmin->forceFill(['must_change_password' => false, 'has_email_authentication' => true])->save();
        $superAdmin->assignRole(User::ROLE_SUPER_ADMIN);
        $this->actingAs($superAdmin);

        // Selama berlangsung: belum bisa dibuka.
        Livewire::test(VoteDetailPage::class)->assertSee('Belum ada pemilihan yang ditutup');

        app(ElectionLifecycle::class)->close($this->election, $this->admin);

        $rows = Livewire::test(VoteDetailPage::class)
            ->callAction('open', ['election' => $this->election->id, 'reason' => 'AUDIT', 'current_password' => 'password'])
            ->assertHasNoFormErrors()
            ->assertSee('Budi Santoso')
            ->instance()
            ->rows();

        $choices = $rows->pluck('choice', 'name');
        $this->assertStringContainsString($this->candidates[0]->name, $choices['Budi Santoso']);
        $this->assertStringContainsString($this->candidates[1]->name, $choices['Siti Aminah']);
        $this->assertTrue(AuditLog::query()->where('action', 'vote_detail.opened')->exists());
    }

    public function test_dispute_period_retention_removes_the_vote_links_for_good(): void
    {
        $this->startAndOpenWave();
        $box = app(BallotBox::class);
        ['attendee' => $attendee, 'pin' => $pin] = $this->register();
        $box->cast($this->election, $attendee, $box->verifyPin($this->election, $attendee, $pin), $this->ballot, $this->candidates[0]);
        app(ElectionLifecycle::class)->close($this->election, $this->admin);

        app(DataRetention::class)->purgeLinkage($this->election->fresh());

        $this->assertSame(0, Vote::query()->whereNotNull('voter_link')->count());
        $this->assertNotNull($this->election->fresh()->vote_links_destroyed_at);
        $this->assertSame(1, app(ResultsCalculator::class)->tally($this->ballot, $this->election->rounds()->first())['valid'], 'Hasil tetap utuh');
    }

    public function test_voters_are_told_their_choice_can_be_opened_only_for_disputes(): void
    {
        $this->get(route('voter.show', $this->election->access_code))
            ->assertOk()
            ->assertSee('Pilihan Anda dirahasiakan');
    }

    public function test_closing_keeps_vote_links_for_disputes_and_tally_counts_correctly(): void
    {
        $this->startAndOpenWave();
        $box = app(BallotBox::class);

        foreach ([0, 0, 1] as $index => $choice) {
            ['attendee' => $attendee, 'pin' => $pin] = $this->register("Warga {$index}");
            $box->cast($this->election, $attendee, $box->verifyPin($this->election, $attendee, $pin), $this->ballot, $this->candidates[$choice]);
        }

        app(ElectionLifecycle::class)->close($this->election, $this->admin);

        $this->assertSame(ElectionStatus::Ditutup, $this->election->fresh()->status);
        $this->assertSame(3, Vote::query()->whereNotNull('voter_link')->count(), 'Tautan disimpan untuk Detail Suara saat sengketa');

        $tally = app(ResultsCalculator::class)->tally($this->ballot, $this->election->rounds()->first());
        $this->assertSame(3, $tally['valid']);
        $this->assertSame(2, $tally['candidates'][0]['votes']);
        $this->assertEqualsWithDelta(66.7, $tally['candidates'][0]['percent'], 0.01);
        $this->assertSame(3, $tally['candidates'][2]['rank']);
    }

    public function test_open_and_assisted_waves_share_one_tally_and_block_repeat_voters(): void
    {
        ['attendee' => $first, 'pin' => $firstPin] = $this->register('Ahmad Sudah');
        ['attendee' => $elder, 'pin' => $elderPin] = $this->register('Mbah Karto');
        $this->startAndOpenWave();
        $box = app(BallotBox::class);
        $waves = app(WaveManager::class);

        $box->cast($this->election, $first, $box->verifyPin($this->election, $first, $firstPin), $this->ballot, $this->candidates[0]);
        $waves->close($this->election, $this->admin);

        $waves->open($this->election, WaveKind::Bantuan, null, $this->admin);
        $this->assertSame(['Mbah Karto'], $box->search($this->election, 'mbah')->pluck('name')->all());
        $this->assertCount(0, $box->search($this->election, 'ahmad'), 'Yang sudah memilih di gelombang 1 tidak muncul lagi');

        try {
            $box->verifyPin($this->election, $first, $firstPin);
            $this->fail('Pemilih gelombang 1 tidak boleh memilih lagi di gelombang 2.');
        } catch (VotingException $exception) {
            $this->assertSame(VotingException::ALREADY_VOTED, $exception->reason);
        }

        $box->cast($this->election, $elder, $box->verifyPin($this->election, $elder, $elderPin), $this->ballot, $this->candidates[0]);
        $waves->close($this->election, $this->admin);
        app(ElectionLifecycle::class)->close($this->election, $this->admin);

        $tally = app(ResultsCalculator::class)->tally($this->ballot, $this->election->rounds()->first());
        $this->assertSame(2, $tally['valid'], 'Suara dua gelombang digabung dalam satu hasil');
        $this->assertSame(2, $tally['candidates'][0]['votes']);
    }

    public function test_assisted_wave_marks_participation_not_vote(): void
    {
        ['attendee' => $attendee, 'pin' => $pin] = $this->register();
        $this->startAndOpenWave(WaveKind::Bantuan, null);
        $box = app(BallotBox::class);
        $box->cast($this->election, $attendee, $box->verifyPin($this->election, $attendee, $pin), $this->ballot, $this->candidates[0]);

        $this->assertTrue((bool) DB::table('attendee_participations')->value('is_assisted'));
        $this->assertNotContains('is_assisted', array_keys((array) DB::table('votes')->first()));
    }

    public function test_paused_election_rejects_votes(): void
    {
        ['attendee' => $attendee, 'pin' => $pin] = $this->register();
        $this->startAndOpenWave();
        $box = app(BallotBox::class);
        $wave = $box->verifyPin($this->election, $attendee, $pin);

        app(ElectionLifecycle::class)->pause($this->election, $this->admin);

        $this->expectExceptionObject(VotingException::paused());
        $box->cast($this->election, $attendee, $wave, $this->ballot, $this->candidates[0]);
    }

    public function test_audit_log_rows_cannot_be_updated_or_deleted_in_database(): void
    {
        app(AuditLogger::class)->log('test.entry', actor: $this->admin);

        try {
            DB::table('audit_logs')->update(['action' => 'diubah']);
            $this->fail('UPDATE seharusnya ditolak trigger.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->delete();
    }

    public function test_audit_chain_detects_tampering(): void
    {
        $logger = app(AuditLogger::class);
        $logger->log('test.one', actor: $this->admin);
        $logger->log('test.two', actor: $this->admin);

        $this->assertNull($logger->findFirstBrokenEntry());

        $forgedId = DB::table('audit_logs')->insertGetId([
            'occurred_at' => now()->format('Y-m-d H:i:s.u'),
            'actor_type' => 'user',
            'action' => 'disusupkan.langsung.ke.database',
            'prev_hash' => str_repeat('0', 64),
            'hash' => str_repeat('f', 64),
        ]);

        $this->assertSame($forgedId, $logger->findFirstBrokenEntry());
    }

    public function test_only_one_election_can_be_live(): void
    {
        $other = Election::factory()->create();
        Candidate::factory()->for(Ballot::factory()->for($other))->create();
        app(ElectionLifecycle::class)->markReady($other, $this->admin);
        app(ElectionLifecycle::class)->start($other, $this->admin);

        $this->expectException(VotingException::class);
        app(ElectionLifecycle::class)->start($this->election, $this->admin);
    }
}
