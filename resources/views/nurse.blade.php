@extends('layouts.app')

@section('title', 'PLP University Clinic')
@section('description', 'Pamantasan ng Lungsod ng Pasig university clinic portal for checking applicants\' medical requirements')
@section('body_class', 'admin')
{{-- data-role="nurse": admin-store.js loads the clinic's state from /api/clinic --}}
@section('body_role', 'nurse')

@push('styles')
  <link href="{{ asset('assets/admin/css/admin.css') }}" rel="stylesheet">
@endpush

@section('content')
  <!-- =========================================================
       SIGN IN (clinic nurse)
       ========================================================= -->
  <div class="auth" id="auth-screen">
    <section class="auth-art">
      <span class="float-star s1" data-icon="star"></span>
      <span class="float-star s2" data-icon="star"></span>
      <span class="float-star s3" data-icon="star"></span>
      <span class="float-star s4" data-icon="star"></span>

      <div class="brand brand-lg">
        <img class="brand-mark brand-logo" src="{{ asset('assets/shared/img/plp-logo.png') }}" alt="" aria-hidden="true">
        <span class="brand-text"><span class="brand-name">Pamantasan ng Lungsod ng Pasig</span><span class="brand-sub">University Clinic</span></span>
      </div>

      <div class="auth-pitch">
        <p class="eyebrow">University clinic</p>
        <h1>Medical files, nothing else.</h1>
        <p>Check the medical requirements applicants send after passing, and send your approval to the admission office.</p>
      </div>

      <div class="journey-preview" aria-label="What the clinic handles">
        <p class="preview-title">Your desk</p>
        <ul class="office-points">
          <li><span data-icon="inbox"></span>Medical files sent by applicants</li>
          <li><span data-icon="flag"></span>Send a file back with a remark</li>
          <li><span data-icon="send"></span>Approve and notify the admission office</li>
        </ul>
      </div>
    </section>

    <section class="auth-panel">
      <div class="auth-card">
        <form id="nurse-signin-form" class="auth-form" novalidate>
          <div class="auth-head">
            <span class="role-chip"><span data-icon="shield"></span>Clinic nurse</span>
            <h2>Sign in to the clinic</h2>
            <p>Use the clinic account issued by the system administrator.</p>
          </div>
          <div>
            <label class="form-label" for="nurse-email">Clinic email</label>
            <input class="form-control" id="nurse-email" type="email" autocomplete="username"
              placeholder="name@plpasig.edu.ph" required>
            <div class="invalid-feedback" id="nurse-email-error"></div>
          </div>
          <div>
            <label class="form-label" for="nurse-password">Password</label>
            <div class="input-wrap">
              <input class="form-control" id="nurse-password" type="password" autocomplete="current-password"
                minlength="8" required>
              <button class="pw-toggle" type="button" aria-label="Show password" aria-pressed="false"
                data-target="nurse-password"><span data-icon="eye"></span></button>
            </div>
            <div class="invalid-feedback" id="nurse-password-error"></div>
          </div>
          <button class="btn btn-brand btn-lg w-100" type="submit" id="nurse-signin-submit"><span
              class="btn-label">Sign in</span></button>
          <p class="demo-note"><span data-icon="help"></span>Clinic accounts only see medical requirements. Accounts are
            managed by the system administrator.</p>
          <a class="link-button align-self-center" href="{{ route('admin') }}"><span data-icon="arrowL"></span>Go to the
            admission office</a>
        </form>
      </div>
    </section>
  </div>

  <!-- =========================================================
       CLINIC PORTAL
       ========================================================= -->
  <div class="app-shell" id="app" hidden>
    <aside class="sidebar" id="sidebar">
      <a class="brand" href="#/check" aria-label="Pamantasan ng Lungsod ng Pasig, medical files to check">
        <img class="brand-mark brand-logo" src="{{ asset('assets/shared/img/plp-logo.png') }}" alt="" aria-hidden="true">
        <span class="brand-text"><span class="brand-name">Pamantasan ng Lungsod ng Pasig</span><span class="brand-sub">University Clinic</span></span>
      </a>

      <p class="sidebar-label">Medical files</p>
      <nav class="sidebar-nav" aria-label="Clinic navigation">
        <span class="nav-indicator" aria-hidden="true"></span>
        <a class="nav-link" href="#/check" data-nav="check"><span data-icon="inbox"></span>To check<span
            class="nav-count" id="nav-count-check">0</span></a>
        <a class="nav-link" href="#/returned" data-nav="returned"><span data-icon="flag"></span>Sent back<span
            class="nav-count" id="nav-count-returned">0</span></a>
        <a class="nav-link" href="#/approved" data-nav="approved"><span data-icon="check"></span>Approved<span
            class="nav-count" id="nav-count-approved">0</span></a>
      </nav>

      <div class="sidebar-footer">
        <span class="avatar" id="sidebar-avatar"></span>
        <div class="user-mini"><strong id="sidebar-name"></strong><span>Clinic nurse</span></div>
        <button class="icon-button logout-button" type="button" aria-label="Sign out" data-bs-toggle="tooltip"
          data-bs-title="Sign out"><span data-icon="logout"></span></button>
      </div>
    </aside>
    <div class="sidebar-backdrop" id="sidebar-backdrop"></div>

    <main class="main-area">
      <header class="topbar">
        <button class="icon-button menu-toggle" type="button" aria-label="Open navigation" aria-expanded="false"><span
            data-icon="menu"></span></button>
        <div class="breadcrumb-text"><span>University clinic</span><span class="crumb-sep">/</span><strong
            id="page-breadcrumb">To check</strong></div>
        <div class="topbar-actions">
          <span class="id-chip" id="period-chip"></span>
        </div>
      </header>

      <div class="content-wrap content-wide">
        <!-- Rendered by assets/nurse/js/nurse.js -->
        <section class="view-section" id="view-list"></section>
        <section class="view-section" id="view-review"></section>
        <footer class="page-footer">© {{ date('Y') }} Pamantasan ng Lungsod ng Pasig · University clinic</footer>
      </div>
    </main>
  </div>

  <!-- =========================================================
       MODALS (the ids admin-ui.js expects)
       ========================================================= -->

  <!-- Send a medical file back: remark -->
  <div class="modal fade" id="remarkModal" tabindex="-1" aria-labelledby="remarkModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" id="remark-form" novalidate>
        <div class="modal-body">
          <span class="modal-icon modal-icon-warn" data-icon="flag"></span>
          <h2 class="modal-title-serif" id="remarkModalTitle">Send this file back</h2>
          <p id="remark-doc" class="mb-3"></p>
          <p class="form-label mb-2">Common reasons</p>
          <div class="remark-presets" id="remark-presets"></div>
          <label class="form-label mt-3" for="remark-text">Remark for the applicant <span class="req">*</span></label>
          <textarea class="form-control" id="remark-text" rows="3" minlength="10" required
            placeholder="Tell the applicant exactly what to fix."></textarea>
          <div class="invalid-feedback" id="remark-text-error"></div>
          <div class="form-text">The applicant sees this remark on their Medical requirements step.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-brand"><span class="btn-label">Send back for resubmission</span></button>
        </div>
      </form>
    </div>
  </div>

  <!-- File preview -->
  <div class="modal fade" id="docModal" tabindex="-1" aria-labelledby="docModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h2 class="modal-title fs-6 fw-bold" id="docModalTitle">Medical file</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" id="doc-preview"></div>
        <div class="modal-footer" id="doc-preview-actions"></div>
      </div>
    </div>
  </div>

  <!-- Confirm (approve) -->
  <div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-body">
          <span class="modal-icon" data-icon="send" id="confirm-icon"></span>
          <h2 class="modal-title-serif" id="confirmModalTitle">Are you sure?</h2>
          <p id="confirm-text"></p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-brand" id="confirm-ok"><span class="btn-label">Confirm</span></button>
        </div>
      </div>
    </div>
  </div>

  <div class="toast-container position-fixed bottom-0 end-0 p-3">
    <div id="portal-toast" class="toast portal-toast" role="status" aria-live="polite" aria-atomic="true">
      <div class="toast-body"><span class="toast-check" data-icon="check"></span><span id="toast-message"></span><button
          type="button" class="btn-close ms-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>
    </div>
  </div>
@endsection

@push('scripts')
  {{-- Shared with the office panel: store (state shape, medStatus) and UI helpers. No office actions. --}}
  <script src="{{ asset('assets/admin/js/admin-store.js') }}"></script>
  <script src="{{ asset('assets/admin/js/admin-ui.js') }}"></script>
  <script src="{{ asset('assets/nurse/js/nurse.js') }}"></script>
@endpush
