{{-- Tombol "Detail" per calon (bila visi & misi publik dinyalakan); membuka jendela dari public.partials.profile-dialogs lewat portal.js. --}}
@if ($candidate->hasProfile())
    <button type="button" class="profile-open" data-profile-open="profil-{{ $candidate->public_id }}">Detail</button>
@endif
