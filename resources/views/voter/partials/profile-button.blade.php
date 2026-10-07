@if ($candidate->hasProfile())
    <button type="button" class="candidate__profile-btn" data-profile-open="profile-{{ $candidate->public_id }}">Lihat visi &amp; misi</button>
@endif
