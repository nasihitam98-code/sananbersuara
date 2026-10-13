{{-- Logo kecil di pita atas (HP pemilih, bilik, layar proyektor): logo dari Pengaturan Tampilan, atau kotak merah bercentang bawaan. --}}
@if ($logo = \App\Models\AppSetting::logoUrl())
    <span class="topbar__logo topbar__logo--image" aria-hidden="true"><img src="{{ $logo }}" alt="" width="40" height="40"></span>
@else
    <span class="topbar__logo" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12.5l5 5L20 6.5"></path></svg>
    </span>
@endif
