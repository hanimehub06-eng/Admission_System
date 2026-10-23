@extends('layouts.app')

@section('title', 'PLP Admission Office')
@section('description', 'Pamantasan ng Lungsod ng Pasig admission office panel for admission personnel')
@section('body_class', 'admin')

@push('styles')
  <link href="{{ asset('assets/admin/css/admin.css') }}" rel="stylesheet">
@endpush

@section('content')
  <!-- =========================================================
       SIGN IN (admission personnel)
       ========================================================= -->
  <div class="auth" id="auth-screen">
    <section class="auth-art">
      <span class="float-star s1" data-icon="star"></span>
      <span class="float-star s2" data-icon="star"></span>
      <span class="float-star s3" data-icon="star"></span>
      <span class="float-star s4" data-icon="star"></span>

      <div class="brand brand-lg">
        <img class="brand-mark brand-logo" src="{{ asset('assets/shared/img/plp-logo.png') }}" alt="" aria-hidden="true">
        <span class="brand-text"><span class="brand-name">Pamantasan ng Lungsod ng Pasig</span><span class="brand-sub">Online Admission</span></span>
      </div>

      <div class="auth-pitch">
        <p class="eyebrow">Admission office · AY 2027–2028</p>
        <h1>Every application, one desk.</h1>
        <p>Review applications, verify documents, schedule entrance exams, and release results from a single record.</p>
      </div>

      <div class="journey-preview" aria-label="What the office handles">
        <p class="preview-title">Your desk</p>
        <ul class="office-points">
          <li><span data-icon="inbox"></span>Review queues with counts by status</li>
          <li><span data-icon="folder"></span>Document verification with remarks</li>
          <li><span data-icon="calendar"></span>Exam batches, rosters and attendance</li>
          <li><span data-icon="shield"></span>Medical clearance after the exam</li>
          <li><span data-icon="chart"></span>Reports without separate spreadsheets</li>
        </ul>
      </div>
    </section>

    <section class="auth-panel">
      <div class="auth-card">
        <form id="admin-signin-form" class="auth-form" novalidate>
          <div class="auth-head">
            <span class="role-chip"><span data-icon="shield"></span>Admission personnel</span>
            <h2>Sign in to the office</h2>
            <p>Use the staff account issued by the system administrator.</p>
          </div>
          <div>
            <label class="form-label" for="admin-email">Staff email</label>
            <input class="form-control" id="admin-email" type="email" autocomplete="username"
              placeholder="name@plpasig.edu.ph" required>
            <div class="invalid-feedback" id="admin-email-error"></div>
          </div>
          <div>
            <label class="form-label" for="admin-password">Password</label>
            <div class="input-wrap">
              <input class="form-control" id="admin-password" type="password" autocomplete="current-password"
                minlength="8" required>
              <button class="pw-toggle" type="button" aria-label="Show password" aria-pressed="false"
                data-target="admin-password"><span data-icon="eye"></span></button>
            </div>
            <div class="invalid-feedback" id="admin-password-error"></div>
          </div>
          <button class="btn btn-brand btn-lg w-100" type="submit" id="admin-signin-submit"><span
              class="btn-label">Sign in</span></button>
          <p class="demo-note"><span data-icon="help"></span>Demo panel: any email and a password of 8 or more
            characters will work. Accounts and roles are managed by the system administrator.</p>
          <a class="link-button align-self-center" href="{{ route('applicant') }}"><span data-icon="arrowL"></span>Go to the
            applicant portal</a>
          <a class="link-button align-self-center" href="{{ route('nurse') }}"><span data-icon="shield"></span>Clinic nurse?
            Go to the clinic portal</a>
        </form>
      </div>
    </section>
  </div>

  <!-- =========================================================
       OFFICE PANEL
       ========================================================= -->
  <div class="app-shell" id="app" hidden>
    <aside class="sidebar" id="sidebar">
      <a class="brand" href="#/dashboard" aria-label="Pamantasan ng Lungsod ng Pasig, dashboard">
        <img class="brand-mark brand-logo" src="{{ asset('assets/shared/img/plp-logo.png') }}" alt="" aria-hidden="true">
        <span class="brand-text"><span class="brand-name">Pamantasan ng Lungsod ng Pasig</span><span class="brand-sub">Online Admission</span></span>
      </a>

      <p class="sidebar-label">Admission office</p>
      <nav class="sidebar-nav" aria-label="Office navigation">
        <span class="nav-indicator" aria-hidden="true"></span>
        <a class="nav-link" href="#/dashboard" data-nav="dashboard"><span data-icon="grid"></span>Dashboard</a>
        <a class="nav-link" href="#/applicants" data-nav="applicants"><span data-icon="users"></span>Applicants</a>
        <a class="nav-link" href="#/documents" data-nav="documents"><span data-icon="folder"></span>Document
          review<span class="nav-count" id="nav-count-documents">0</span></a>
        <a class="nav-link" href="#/exams" data-nav="exams"><span data-icon="calendar"></span>Entrance exams</a>
        <a class="nav-link" href="#/results" data-nav="results"><span data-icon="award"></span>Results<span
            class="nav-count" id="nav-count-results">0</span></a>
        <a class="nav-link" href="#/medical" data-nav="medical"><span data-icon="shield"></span>Medical</a>
      </nav>

      <!-- Less frequent screens, folded away; opens by itself when one of them is the current page -->
      <details class="sidebar-more" id="sidebar-more">
        <summary class="sidebar-label">More</summary>
        <nav class="sidebar-nav sidebar-nav-plain" aria-label="More navigation">
          <a class="nav-link" href="#/reports" data-nav="reports"><span data-icon="chart"></span>Reports</a>
          <a class="nav-link" href="#/settings" data-nav="settings"><span data-icon="settings"></span>Programs &amp;
            requirements</a>
          <a class="nav-link" href="#/audit" data-nav="audit"><span data-icon="history"></span>Audit log</a>
        </nav>
      </details>

      <div class="sidebar-footer">
        <span class="avatar" id="sidebar-avatar">MS</span>
        <div class="user-mini"><strong id="sidebar-name">Maria Santos</strong><span>Admission personnel</span></div>
        <button class="icon-button logout-button" type="button" aria-label="Sign out" data-bs-toggle="tooltip"
          data-bs-title="Sign out"><span data-icon="logout"></span></button>
      </div>
    </aside>
    <div class="sidebar-backdrop" id="sidebar-backdrop"></div>

    <main class="main-area">
      <header class="topbar">
        <button class="icon-button menu-toggle" type="button" aria-label="Open navigation" aria-expanded="false"><span
            data-icon="menu"></span></button>
        <div class="breadcrumb-text"><span>Admission office</span><span class="crumb-sep">/</span><strong
            id="page-breadcrumb">Dashboard</strong></div>
        <div class="topbar-actions">
          <form class="topbar-search" id="quick-search" role="search">
            <span data-icon="search"></span>
            <input type="search" id="quick-search-input" placeholder="Find applicant, ID or LRN"
              aria-label="Find an applicant by name, ID or LRN">
          </form>
          <span class="id-chip"><span>AY</span>2027–2028</span>
        </div>
      </header>

      <div class="content-wrap content-wide">
        <!-- Each view renders into its section; see assets/admin/js/views -->
        <section class="view-section" id="view-dashboard"></section>
        <section class="view-section" id="view-applicants"></section>
        <section class="view-section" id="view-applicant"></section>
        <section class="view-section" id="view-documents"></section>
        <section class="view-section" id="view-docreview"></section>
        <section class="view-section" id="view-exams"></section>
        <section class="view-section" id="view-session"></section>
        <section class="view-section" id="view-results"></section>
        <section class="view-section" id="view-medical"></section>
        <section class="view-section" id="view-reports"></section>
        <section class="view-section" id="view-settings"></section>
        <section class="view-section" id="view-audit"></section>
        <footer class="page-footer">© 2026 Pamantasan ng Lungsod ng Pasig · Admission office</footer>
      </div>
    </main>
  </div>

  <!-- =========================================================
       MODALS
       ========================================================= -->

  <!-- Requires resubmission: remark -->
  <div class="modal fade" id="remarkModal" tabindex="-1" aria-labelledby="remarkModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" id="remark-form" novalidate>
        <div class="modal-body">
          <span class="modal-icon modal-icon-warn" data-icon="flag"></span>
          <h2 class="modal-title-serif" id="remarkModalTitle">Ask for a resubmission</h2>
          <p id="remark-doc" class="mb-3"></p>
          <p class="form-label mb-2">Common reasons</p>
          <div class="remark-presets" id="remark-presets"></div>
          <label class="form-label mt-3" for="remark-text">Remark for the applicant <span class="req">*</span></label>
          <textarea class="form-control" id="remark-text" rows="3" minlength="10" required
            placeholder="Tell the applicant exactly what to fix."></textarea>
          <div class="invalid-feedback" id="remark-text-error"></div>
          <div class="form-text">The applicant sees this remark on their Documents page.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-brand"><span class="btn-label">Send back for resubmission</span></button>
        </div>
      </form>
    </div>
  </div>

  <!-- Approve for exam: choose a session -->
  <div class="modal fade" id="approveModal" tabindex="-1" aria-labelledby="approveModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" id="approve-form" novalidate>
        <div class="modal-body">
          <span class="modal-icon" data-icon="calendar"></span>
          <h2 class="modal-title-serif" id="approveModalTitle">Approve for the entrance exam</h2>
          <p id="approve-who" class="mb-3"></p>
          <p class="form-label mb-2">Exam session</p>
          <div class="session-options" id="approve-sessions" role="radiogroup" aria-label="Exam session"></div>
          <div class="invalid-feedback" id="approve-error"></div>
          <div class="form-text mt-2">The applicant gets a printable exam permit once you approve.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-brand"><span class="btn-label">Approve and schedule</span></button>
        </div>
      </form>
    </div>
  </div>

  <!-- Record result -->
  <div class="modal fade" id="resultModal" tabindex="-1" aria-labelledby="resultModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" id="result-form" novalidate>
        <div class="modal-body">
          <span class="modal-icon" data-icon="award"></span>
          <h2 class="modal-title-serif" id="resultModalTitle">Record exam result</h2>
          <p id="result-who" class="mb-3"></p>
          <label class="form-label" for="result-score">Exam score (out of 100) <span class="req">*</span></label>
          <input class="form-control" id="result-score" type="number" inputmode="numeric" min="0" max="100" step="1"
            data-msg-range="Enter a score from 0 to 100." required>
          <div class="invalid-feedback" id="result-score-error"></div>
          <div class="form-text"><span data-icon="lock" class="inline-ico"></span>Internal only. Applicants see Passed or
            Did not pass, never the score.</div>
          <p class="form-label mt-3 mb-2">Decision</p>
          <div class="segmented" role="radiogroup" aria-label="Decision">
            <input type="radio" class="btn-check" name="decision" id="decision-passed" value="passed">
            <label for="decision-passed"><span data-icon="check"></span>Passed</label>
            <input type="radio" class="btn-check" name="decision" id="decision-failed" value="failed">
            <label for="decision-failed"><span data-icon="x"></span>Did not pass</label>
          </div>
          <p class="form-text mt-2" id="result-hint"></p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-brand"><span class="btn-label">Save result</span></button>
        </div>
      </form>
    </div>
  </div>

  <!-- New exam session -->
  <div class="modal fade" id="sessionModal" tabindex="-1" aria-labelledby="sessionModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" id="session-form" novalidate>
        <div class="modal-body">
          <span class="modal-icon" data-icon="calendar"></span>
          <h2 class="modal-title-serif" id="sessionModalTitle">New exam session</h2>
          <div class="row g-3">
            <div class="col-sm-6">
              <label class="form-label" for="session-date">Exam date <span class="req">*</span></label>
              <input class="form-control" id="session-date" type="date" required>
              <div class="invalid-feedback" id="session-date-error"></div>
            </div>
            <div class="col-sm-6">
              <label class="form-label" for="session-batch">Batch <span class="req">*</span></label>
              <select class="form-select" id="session-batch" required>
                <option value="A">Batch A · morning</option>
                <option value="B">Batch B · afternoon</option>
              </select>
              <div class="invalid-feedback" id="session-batch-error"></div>
            </div>
            <div class="col-sm-8">
              <label class="form-label" for="session-venue">Venue <span class="req">*</span></label>
              <input class="form-control" id="session-venue" placeholder="e.g. Main Building · Room 301" required>
              <div class="invalid-feedback" id="session-venue-error"></div>
            </div>
            <div class="col-sm-4">
              <label class="form-label" for="session-capacity">Seats <span class="req">*</span></label>
              <input class="form-control" id="session-capacity" type="number" min="5" max="500" value="30"
                data-msg-range="Use 5 to 500 seats." required>
              <div class="invalid-feedback" id="session-capacity-error"></div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-brand"><span class="btn-label">Create session</span></button>
        </div>
      </form>
    </div>
  </div>

  <!-- Add program -->
  <div class="modal fade" id="programModal" tabindex="-1" aria-labelledby="programModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" id="program-form" novalidate>
        <div class="modal-body">
          <span class="modal-icon" data-icon="book"></span>
          <h2 class="modal-title-serif" id="programModalTitle">Add a program</h2>
          <div class="row g-3">
            <div class="col-sm-4">
              <label class="form-label" for="program-code">Code <span class="req">*</span></label>
              <input class="form-control text-uppercase" id="program-code" maxlength="10" pattern="^[A-Za-z]{2,10}$"
                data-msg-pattern="Use 2 to 10 letters, like BSIT." required>
              <div class="invalid-feedback" id="program-code-error"></div>
            </div>
            <div class="col-sm-8">
              <label class="form-label" for="program-name">Program name <span class="req">*</span></label>
              <input class="form-control" id="program-name" required>
              <div class="invalid-feedback" id="program-name-error"></div>
            </div>
            <div class="col-sm-4">
              <label class="form-label" for="program-slots">Slots <span class="req">*</span></label>
              <input class="form-control" id="program-slots" type="number" min="1" max="2000" value="50"
                data-msg-range="Use 1 to 2,000 slots." required>
              <div class="invalid-feedback" id="program-slots-error"></div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-brand"><span class="btn-label">Add program</span></button>
        </div>
      </form>
    </div>
  </div>

  <!-- Document preview -->
  <div class="modal fade" id="docModal" tabindex="-1" aria-labelledby="docModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h2 class="modal-title fs-6 fw-bold" id="docModalTitle">Document</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" id="doc-preview"></div>
        <div class="modal-footer" id="doc-preview-actions"></div>
      </div>
    </div>
  </div>

  <!-- Generic confirm -->
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

  <!-- Filled just before window.print() -->
  <div id="print-area" aria-hidden="true"></div>
@endsection

@push('scripts')
  <script src="{{ asset('assets/admin/js/admin-store.js') }}"></script>
  <script src="{{ asset('assets/admin/js/admin-actions.js') }}"></script>
  <script src="{{ asset('assets/admin/js/admin-ui.js') }}"></script>
  <script src="{{ asset('assets/admin/js/views/dashboard.js') }}"></script>
  <script src="{{ asset('assets/admin/js/views/applicants.js') }}"></script>
  <script src="{{ asset('assets/admin/js/views/documents.js') }}"></script>
  <script src="{{ asset('assets/admin/js/views/exams.js') }}"></script>
  <script src="{{ asset('assets/admin/js/views/results.js') }}"></script>
  <script src="{{ asset('assets/admin/js/views/medical.js') }}"></script>
  <script src="{{ asset('assets/admin/js/views/reports.js') }}"></script>
  <script src="{{ asset('assets/admin/js/views/settings.js') }}"></script>
  <script src="{{ asset('assets/admin/js/views/audit.js') }}"></script>
  <script src="{{ asset('assets/admin/js/admin.js') }}"></script>
@endpush
