@extends('layouts.app')

@section('title', 'PLP Online Admission · Applicant Portal')
@section('description', 'Pamantasan ng Lungsod ng Pasig online admission: applicant portal')

@push('styles')
  <link href="{{ \App\Support\Asset::v('assets/applicant/css/applicant.css') }}" rel="stylesheet">
@endpush

@section('content')
  <!-- =========================================================
       SIGN IN / CREATE ACCOUNT
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
        <p class="eyebrow">AY 2027–2028 admissions</p>
        <h1>Your admission, one step at a time.</h1>
        <p>Fill in your application, upload your requirements, and follow every stage until your result comes in.</p>
      </div>

    </section>

    <section class="auth-panel">
      <div class="auth-card">
        <div class="auth-tabs" role="tablist" aria-label="Account">
          <span class="auth-tab-pill" aria-hidden="true"></span>
          <button class="auth-tab active" type="button" role="tab" id="tab-signin" aria-selected="true"
            aria-controls="signin-form">Sign in</button>
          <button class="auth-tab" type="button" role="tab" id="tab-signup" aria-selected="false"
            aria-controls="signup-form">Create account</button>
        </div>

        <!-- Sign in -->
        <form id="signin-form" class="auth-form" role="tabpanel" aria-labelledby="tab-signin" novalidate>
          <div class="auth-head">
            <h2>Welcome back</h2>
            <p>Sign in to continue your application.</p>
          </div>
          <div>
            <label class="form-label" for="signin-email">Email address</label>
            <input class="form-control" id="signin-email" type="email" autocomplete="email" placeholder="you@email.com"
              required>
            <div class="invalid-feedback" id="signin-email-error"></div>
          </div>
          <div>
            <label class="form-label" for="signin-password">Password</label>
            <div class="input-wrap">
              <input class="form-control" id="signin-password" type="password" autocomplete="current-password"
                minlength="8" required>
              <button class="pw-toggle" type="button" aria-label="Show password" aria-pressed="false"
                data-target="signin-password"><span data-icon="eye"></span></button>
            </div>
            <div class="invalid-feedback" id="signin-password-error"></div>
          </div>
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="remember-me">
              <label class="form-check-label" for="remember-me">Remember me</label>
            </div>
            <button class="link-button" type="button" id="forgot-link">Forgot password?</button>
          </div>
          @include('partials.captcha')
          <button class="btn btn-brand btn-lg w-100" type="submit" id="signin-submit"><span class="btn-label">Sign
              in</span></button>
        </form>

        <!-- Create account -->
        <form id="signup-form" class="auth-form" role="tabpanel" aria-labelledby="tab-signup" novalidate hidden>
          <div class="auth-head">
            <h2>Create your account</h2>
            <p>You'll use this account for your whole admission journey.</p>
          </div>
          <div class="row g-3">
            <div class="col-sm-6">
              <label class="form-label" for="signup-first">First name</label>
              <input class="form-control" id="signup-first" autocomplete="given-name" maxlength="100" pattern="^\p{L}[\p{L} .'\-]*$" data-msg-pattern="Use letters only (spaces, hyphens, periods and apostrophes are fine)." required>
              <div class="invalid-feedback" id="signup-first-error"></div>
            </div>
            <div class="col-sm-6">
              <label class="form-label" for="signup-last">Last name</label>
              <input class="form-control" id="signup-last" autocomplete="family-name" maxlength="100" pattern="^\p{L}[\p{L} .'\-]*$" data-msg-pattern="Use letters only (spaces, hyphens, periods and apostrophes are fine)." required>
              <div class="invalid-feedback" id="signup-last-error"></div>
            </div>
            <div class="col-12">
              <label class="form-label" for="signup-email">Email address</label>
              <input class="form-control" id="signup-email" type="email" autocomplete="email" maxlength="255" required>
              <div class="invalid-feedback" id="signup-email-error"></div>
            </div>
            <div class="col-12">
              <label class="form-label" for="signup-phone">Mobile number</label>
              <input class="form-control" id="signup-phone" type="tel" autocomplete="tel" maxlength="16" placeholder="+63 9XX XXX XXXX"
                pattern="^(\+63|0)\s?9\d{2}\s?\d{3}\s?\d{4}$"
                data-msg-pattern="Use a PH mobile number, like +63 917 123 4567." required>
              <div class="invalid-feedback" id="signup-phone-error"></div>
            </div>
            <div class="col-sm-6">
              <label class="form-label" for="signup-password">Password</label>
              <div class="input-wrap">
                <input class="form-control" id="signup-password" type="password" autocomplete="new-password"
                  minlength="8" pattern="(?=.*\p{L})(?=.*\d).*" data-msg-pattern="Use at least one letter and one number." required>
                <button class="pw-toggle" type="button" aria-label="Show password" aria-pressed="false"
                  data-target="signup-password"><span data-icon="eye"></span></button>
              </div>
              <div class="invalid-feedback" id="signup-password-error"></div>
            </div>
            <div class="col-sm-6">
              <label class="form-label" for="signup-confirm">Confirm password</label>
              <input class="form-control" id="signup-confirm" type="password" autocomplete="new-password" required>
              <div class="invalid-feedback" id="signup-confirm-error"></div>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="signup-terms" required>
                <label class="form-check-label" for="signup-terms">I have read and agree to the <button class="link-button"
                    type="button" data-bs-toggle="modal" data-bs-target="#termsModal">terms and conditions and the data
                    privacy notice</button> (RA 10173).</label>
              </div>
              <div class="invalid-feedback" id="signup-terms-error"></div>
            </div>
          </div>
          @include('partials.captcha')
          <button class="btn btn-brand btn-lg w-100" type="submit" id="signup-submit"><span class="btn-label">Create
              account</span></button>
        </form>

        <!-- Forgot password -->
        <form id="forgot-form" class="auth-form" novalidate hidden>
          <div class="auth-head">
            <h2>Reset your password</h2>
            <p>Enter the email you used to sign up and we'll send you a reset link.</p>
          </div>
          <div>
            <label class="form-label" for="forgot-email">Email address</label>
            <input class="form-control" id="forgot-email" type="email" autocomplete="email" maxlength="255" required>
            <div class="invalid-feedback" id="forgot-email-error"></div>
          </div>
          <p class="form-note" id="forgot-note" role="status"></p>
          @include('partials.captcha')
          <button class="btn btn-brand btn-lg w-100" type="submit">Send reset link</button>
          <button class="link-button align-self-center back-to-signin" type="button"><span
              data-icon="arrowL"></span>Back to sign in</button>
        </form>

        <!-- Email code (two-step sign-in and sign-up) -->
        <form id="code-form" class="auth-form" novalidate hidden>
          <div class="auth-head">
            <h2 id="code-title">Check your email</h2>
            <p>We sent a 6-digit code to <strong id="code-email"></strong>. It expires in 10 minutes.</p>
          </div>
          <div>
            <label class="form-label" for="code-input">Code</label>
            <input class="form-control code-input" id="code-input" type="text" inputmode="numeric"
              autocomplete="one-time-code" maxlength="6" pattern="^\d{6}$" placeholder="000000"
              data-msg-pattern="Enter the 6 digits from the email." required>
            <div class="invalid-feedback" id="code-input-error"></div>
          </div>
          <p class="form-note" id="code-note" role="status"></p>
          <button class="btn btn-brand btn-lg w-100" type="submit" id="code-submit"><span
              class="btn-label">Verify</span></button>
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <button class="link-button back-to-signin" type="button"><span data-icon="arrowL"></span>Back to sign
              in</button>
            <button class="link-button" type="button" id="code-resend">Resend code</button>
          </div>
        </form>
      </div>
    </section>
  </div>

  <!-- =========================================================
       PORTAL
       ========================================================= -->
  <div class="app-shell" id="app" hidden>
    <aside class="sidebar" id="sidebar">
      <a class="brand" href="#" data-view="overview" aria-label="Pamantasan ng Lungsod ng Pasig, overview">
        <img class="brand-mark brand-logo" src="{{ asset('assets/shared/img/plp-logo.png') }}" alt="" aria-hidden="true">
        <span class="brand-text"><span class="brand-name">Pamantasan ng Lungsod ng Pasig</span><span class="brand-sub">Online Admission</span></span>
      </a>

      <p class="sidebar-label">Applicant space</p>
      <nav class="sidebar-nav" aria-label="Applicant navigation">
        <span class="nav-indicator" aria-hidden="true"></span>
        <a class="nav-link" href="#" data-view="overview"><span data-icon="home"></span>Overview</a>
        <a class="nav-link active" href="#" data-view="application"><span data-icon="file"></span>My application<span
            class="nav-count" id="nav-app-count">0/3</span></a>
        <a class="nav-link" href="#" data-view="documents"><span data-icon="folder"></span>Documents<span
            class="nav-count" id="nav-doc-count">0</span></a>
      </nav>

      <div class="sidebar-help">
        <span class="help-icon" data-icon="help"></span>
        <h3>Need a hand?</h3>
        <p>Admissions office, Mon–Fri, 8 AM to 5 PM.</p>
        <p class="help-contact">admissions@plpasig.edu.ph</p>
      </div>

      <div class="sidebar-footer">
        <span class="avatar" id="sidebar-avatar">JM</span>
        <div class="user-mini"><strong id="sidebar-name">Jordan Mendoza</strong><span>Applicant</span></div>
        <button class="icon-button logout-button" type="button" aria-label="Sign out" data-bs-toggle="tooltip"
          data-bs-title="Sign out"><span data-icon="logout"></span></button>
      </div>
    </aside>
    <div class="sidebar-backdrop" id="sidebar-backdrop"></div>

    <main class="main-area">
      <header class="topbar">
        <button class="icon-button menu-toggle" type="button" aria-label="Open navigation" aria-expanded="false"><span
            data-icon="menu"></span></button>
        <div class="breadcrumb-text"><span>Applicant space</span><span class="crumb-sep">/</span><strong
            id="page-breadcrumb">My application</strong></div>
        <div class="topbar-actions">
          <span class="id-chip"><span>ID</span>AD-2026-08421</span>
          <button class="profile-button" type="button" data-view="profile" aria-label="Open my profile"><span
              class="avatar avatar-sm" id="topbar-avatar">JM</span><span class="profile-name" id="topbar-name">Jordan
              Mendoza</span><span class="chevron" data-icon="chevron"></span></button>
        </div>
      </header>

      <div class="content-wrap">
        <!-- ===================== OVERVIEW ===================== -->
        <section class="view-section" id="view-overview">
          <div class="welcome-row" data-reveal>
            <div>
              <p class="eyebrow">AY 2027–2028 admission</p>
              <h1 id="greeting">Good morning, Jordan</h1>
              <p class="lede">Follow your admission from the first form to your result.</p>
            </div>
            <div class="application-id"><span>Application ID</span><strong>AD-2026-08421</strong></div>
          </div>

          <section class="journey-card" data-reveal>
            <div class="journey-head">
              <div>
                <span class="eyebrow">Admission journey</span>
                <h2 id="journey-title">Step 1 of 6 · Application form</h2>
              </div>
              <span class="status-pill draft" id="status-pill">Draft</span>
            </div>
            <div class="journey-progress">
              <div class="progress-track" role="progressbar" aria-label="Admission progress" aria-valuemin="0"
                aria-valuemax="100" aria-valuenow="0" id="journey-progress"><span id="journey-progress-bar"></span>
              </div>
              <strong><span class="count" id="journey-percent">0</span>%</strong>
            </div>
            <div class="stepper-scroll">
              <ol class="stepper stepper-6" id="journey-stepper" aria-label="Admission stages"></ol>
            </div>
            <div class="stage-panel" id="stage-panel" aria-live="polite"></div>
          </section>

          <div class="row g-3">
            <div class="col-lg-7" data-reveal>
              <section class="card-soft docs-summary">
                <div class="panel-heading">
                  <div>
                    <h2>Required documents</h2>
                    <p>Upload all <span class="doc-total">7</span> to send your application for review.</p>
                  </div>
                  <a class="text-link" href="#" data-view="documents">Open documents<span data-icon="arrowR"></span></a>
                </div>
                <div class="docs-summary-body">
                  <div class="ring" id="overview-ring">
                    <svg viewBox="0 0 120 120" aria-hidden="true">
                      <circle class="ring-track" cx="60" cy="60" r="52" />
                      <circle class="ring-fill" cx="60" cy="60" r="52" />
                    </svg>
                    <div class="ring-label"><strong class="count" id="overview-doc-count">0</strong><span>of <span class="doc-total">7</span></span>
                    </div>
                  </div>
                  <ul class="doc-mini-list" id="doc-mini-list"></ul>
                </div>
              </section>
            </div>
            <div class="col-lg-5 d-flex flex-column gap-3" data-reveal>
              <section class="card-soft program-panel">
                <div class="panel-heading">
                  <div>
                    <h2>Program choice</h2>
                    <p>AY 2027–2028 · 1st semester</p>
                  </div>
                  <button class="link-button" type="button" data-goto="3">Edit</button>
                </div>
                <div class="program-content">
                  <span class="program-icon" data-icon="star"></span>
                  <div>
                    <span class="eyebrow">First choice</span>
                    <h3 id="program-first">Bachelor of Science in Information Technology</h3>
                    <p id="program-second">Second choice not set</p>
                  </div>
                </div>
              </section>
              <section class="card-soft dates-panel">
                <div class="panel-heading">
                  <div>
                    <h2>Important dates to remember</h2>
                    <p>Deadlines and schedules for your admission</p>
                  </div>
                </div>
                <ul class="key-dates" id="key-dates"></ul>
              </section>
              <!-- FR-11: dated status timeline -->
              <section class="card-soft dates-panel" id="timeline-panel" hidden>
                <div class="panel-heading">
                  <div>
                    <h2>Status history</h2>
                    <p>Each step of your application, with its date</p>
                  </div>
                </div>
                <ul class="key-dates" id="status-timeline"></ul>
              </section>
            </div>
          </div>

          <section class="demo-strip" data-reveal>
            <div>
              <strong>Demo controls</strong>
              <p>Move through the journey without filling everything in. Remove this panel before going live.</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
              <button class="btn btn-ghost" type="button" id="demo-reset">Reset</button>
              <button class="btn btn-ghost" type="button" id="demo-advance">Advance one stage<span
                  data-icon="arrowR"></span></button>
            </div>
          </section>
          <footer class="page-footer">© 2026 Pamantasan ng Lungsod ng Pasig · Online Admission</footer>
        </section>

        <!-- ===================== APPLICATION ===================== -->
        <section class="view-section active" id="view-application">
          <div class="page-title" data-reveal>
            <p class="eyebrow">Your details</p>
            <h1>My application</h1>
            <p class="lede">Three short sections. Finish each one to unlock the next. Your answers save automatically on
              this device.</p>
          </div>

          <div class="stepper-card" data-reveal>
            <ol class="stepper stepper-3" id="app-stepper" aria-label="Application sections"></ol>
          </div>

          <div class="form-card" id="form-card" data-reveal>
            <div class="form-card-head">
              <div>
                <p class="eyebrow" id="stage-count">Section 1 of 3</p>
                <h2 id="stage-title">Personal information</h2>
                <p id="stage-desc">Tell us who you are and how to reach you.</p>
              </div>
              <span class="save-state" id="save-state" role="status"><span data-icon="check"></span>Saved on this
                device</span>
            </div>

            <form id="application-form" novalidate>
              <!-- Section 1 -->
              <section class="stage" data-stage="1">
                <div class="row g-3">
                  <div class="col-md-4"><label class="form-label" for="last-name">Surname <span
                        class="req">*</span></label><input class="form-control" id="last-name" name="lastName"
                      autocomplete="family-name" required>
                    <div class="invalid-feedback" id="last-name-error"></div>
                  </div>
                  <div class="col-md-4"><label class="form-label" for="first-name">Given name <span
                        class="req">*</span></label><input class="form-control" id="first-name" name="firstName"
                      autocomplete="given-name" maxlength="100" pattern="^\p{L}[\p{L} .'\-]*$" data-msg-pattern="Use letters only (spaces, hyphens, periods and apostrophes are fine)." required>
                    <div class="invalid-feedback" id="first-name-error"></div>
                  </div>
                  <div class="col-md-4"><label class="form-label" for="middle-name">Middle name</label><input
                      class="form-control" id="middle-name" name="middleName" autocomplete="additional-name" maxlength="100" pattern="^\p{L}[\p{L} .'\-]*$" data-msg-pattern="Use letters only (spaces, hyphens, periods and apostrophes are fine).">
                    <div class="invalid-feedback" id="middle-name-error"></div>
                  </div>
                  <div class="col-md-4"><label class="form-label" for="sex">Sex <span
                        class="req">*</span></label><select class="form-select" id="sex" name="sex" required>
                      <option value="">Select</option>
                      <option>Female</option>
                      <option>Male</option>
                      <option>Prefer not to say</option>
                    </select>
                    <div class="invalid-feedback" id="sex-error"></div>
                  </div>
                  <div class="col-md-4"><label class="form-label" for="birth-date">Birthday <span
                        class="req">*</span></label><input class="form-control" id="birth-date" name="birthDate"
                      type="date" required>
                    <div class="invalid-feedback" id="birth-date-error"></div>
                  </div>
                  <div class="col-md-4"><label class="form-label" for="phone">Contact number <span
                        class="req">*</span></label><input class="form-control" id="phone" name="phone" type="tel"
                      autocomplete="tel" placeholder="+63 9XX XXX XXXX" pattern="^(\+63|0)\s?9\d{2}\s?\d{3}\s?\d{4}$"
                      data-msg-pattern="Use a PH mobile number, like +63 917 123 4567." required>
                    <div class="invalid-feedback" id="phone-error"></div>
                  </div>
                  <div class="col-12"><label class="form-label" for="email">Email address <span
                        class="req">*</span></label><input class="form-control" id="email" name="email" type="email"
                      autocomplete="email" required>
                    <div class="invalid-feedback" id="email-error"></div>
                  </div>
                  <div class="col-md-4"><label class="form-label" for="is-resident">Residency status <span
                        class="req">*</span></label><select class="form-select" id="is-resident" name="isResident"
                      required>
                      <option value="">Select</option>
                      <option value="1">Pasig resident</option>
                      <option value="0">Not a Pasig resident</option>
                    </select>
                    <div class="invalid-feedback" id="is-resident-error"></div>
                  </div>
                  <div class="col-md-4 resident-only"><label class="form-label" for="district">District <span
                        class="req">*</span></label><select class="form-select" id="district" name="district" required>
                      <option value="">Select</option>
                      <option value="1">District 1</option>
                      <option value="2">District 2</option>
                    </select>
                    <div class="invalid-feedback" id="district-error"></div>
                  </div>
                  <div class="col-md-4 resident-only"><label class="form-label" for="barangay">Barangay <span
                        class="req">*</span></label><select class="form-select" id="barangay" name="barangay"
                      data-barangays='@json(\App\Models\Barangay::byDistrict())' required>
                      <option value="">Select a district first</option>
                    </select>
                    <div class="invalid-feedback" id="barangay-error"></div>
                  </div>
                  <div class="col-12"><label class="form-label" for="address">Address <span
                        class="req">*</span></label><input class="form-control" id="address" name="address"
                      autocomplete="street-address" placeholder="House no., street, city" required>
                    <div class="invalid-feedback" id="address-error"></div>
                  </div>
                  <div class="col-md-6"><label class="form-label" for="has-guardian">Do you live with a guardian instead
                      of a parent?</label><select class="form-select" id="has-guardian" name="hasGuardian">
                      <option value="0">No</option>
                      <option value="1">Yes (a notarized affidavit of guardianship is required)</option>
                    </select>
                  </div>
                  <!-- FR-31: applicants under 18 need a parent's or guardian's consent -->
                  <div class="col-md-6 minor-only" hidden><label class="form-label" for="guardian-consent-name">Parent or
                      guardian giving consent <span class="req">*</span></label><input class="form-control"
                      id="guardian-consent-name" name="guardianConsentName" autocomplete="off" placeholder="Full name"
                      maxlength="150" required disabled>
                    <div class="invalid-feedback" id="guardian-consent-name-error"></div>
                  </div>
                  <div class="col-12 minor-only" hidden><label class="form-label" for="guardian-consent">Parent or
                      guardian consent <span class="req">*</span></label><select class="form-select" id="guardian-consent"
                      name="guardianConsent" required disabled>
                      <option value="">Select</option>
                      <option value="1">Yes. My parent or guardian has read the data privacy notice (RA 10173) and agrees
                        to the processing of my personal data for admission.</option>
                    </select>
                    <div class="form-text">You're under 18, so a parent or guardian must consent.</div>
                    <div class="invalid-feedback" id="guardian-consent-error"></div>
                  </div>
                </div>
              </section>

              <!-- Section 2 -->
              <section class="stage" data-stage="2">
                <div class="row g-3">
                  <div class="col-md-6"><label class="form-label" for="lrn">Learner Reference Number (LRN) <span
                        class="req">*</span></label><input class="form-control" id="lrn" name="lrn" inputmode="numeric"
                      pattern="^\d{12}$" placeholder="12 digits" data-msg-pattern="The LRN has exactly 12 digits."
                      required>
                    <div class="invalid-feedback" id="lrn-error"></div>
                    <div class="form-text">Printed on your Form 137 / Form 138.</div>
                  </div>
                  <div class="col-md-6"><label class="form-label" for="school-type">Type of school <span
                        class="req">*</span></label><select class="form-select" id="school-type" name="schoolType"
                      required>
                      <option value="">Select</option>
                      <option>Public</option>
                      <option>Private</option>
                    </select>
                    <div class="invalid-feedback" id="school-type-error"></div>
                  </div>
                  <div class="col-12"><label class="form-label" for="last-school">Last school attended <span
                        class="req">*</span></label><input class="form-control" id="last-school" name="lastSchool"
                      placeholder="Full school name" required>
                    <div class="invalid-feedback" id="last-school-error"></div>
                  </div>
                  <div class="col-md-6"><label class="form-label" for="strand">SHS strand <span
                        class="req">*</span></label><select class="form-select" id="strand" name="strand" required>
                      <option value="">Select</option>
                      @foreach (\App\Models\Strand::activeNames() as $strand)
                        <option>{{ $strand }}</option>
                      @endforeach
                    </select>
                    <div class="invalid-feedback" id="strand-error"></div>
                  </div>
                  <div class="col-md-6"><label class="form-label" for="grad-year">Year graduated <span
                        class="req">*</span></label><select class="form-select" id="grad-year" name="gradYear" required>
                      <option value="">Select</option>
                      <option>2027 (expected)</option>
                      <option>2026</option>
                      <option>2025</option>
                      <option>2024 or earlier</option>
                    </select>
                    <div class="invalid-feedback" id="grad-year-error"></div>
                    <div class="form-text">Decides whether you upload Form 137 (graduates) or Form 138 (Grade 12).</div>
                  </div>
                </div>
              </section>

              <!-- Section 3 -->
              <section class="stage" data-stage="3">
                <div class="row g-4">
                  <div class="col-lg-7">
                    <div class="row g-3">
                      <!-- Drop targets for the program list (jQuery UI Droppable); the dropdowns work as before -->
                      <div class="col-12 choice-drop" data-choice="1"><label class="form-label" for="program1">First-choice program <span
                            class="req">*</span></label><select class="form-select program-select" id="program1"
                          name="program1" required>
                          <option value="">Select a program</option>
                        </select>
                        <div class="invalid-feedback" id="program1-error"></div>
                        <!-- FR-06: programs suggested for the applicant's SHS strand -->
                        <div class="form-text" id="strand-suggestions" hidden></div>
                      </div>
                      <div class="col-12 choice-drop" data-choice="2"><label class="form-label" for="program2">Second-choice program <span
                            class="req">*</span></label><select class="form-select program-select" id="program2"
                          name="program2" required>
                          <option value="">Select a program</option>
                        </select>
                        <div class="invalid-feedback" id="program2-error"></div>
                      </div>
                      <div class="col-12 choice-drop" data-choice="3"><label class="form-label" for="program3">Third-choice program <span
                            class="req">*</span></label><select class="form-select program-select" id="program3"
                          name="program3" required>
                          <option value="">Select a program</option>
                        </select>
                        <div class="invalid-feedback" id="program3-error"></div>
                      </div>
                      <div class="col-sm-8"><label class="form-label" for="intake">Intake</label><input class="form-control"
                          id="intake" value="AY 2027–2028 · 1st semester" readonly></div>
                    </div>
                  </div>
                  <!-- Program list: drag a program onto a choice (jQuery UI Draggable), or click it to fill the next empty one -->
                  <div class="col-lg-5 pp-col">
                    <aside class="program-picker" aria-labelledby="program-picker-title">
                      <div class="pp-head">
                        <h3 id="program-picker-title">Programs</h3>
                        <p>Drag a program onto a choice, or click it to fill the next empty one.</p>
                      </div>
                      <div class="pp-search"><span data-icon="search"></span><input class="form-control" id="program-filter"
                          type="search" placeholder="Search programs" aria-label="Search programs"></div>
                      <div class="pp-list" id="program-list" role="list"></div>
                    </aside>
                  </div>
                </div>
              </section>

              <div class="form-actions">
                <button class="btn btn-ghost" type="button" id="btn-back"><span data-icon="arrowL"></span>Back</button>
                <button class="btn btn-brand" type="submit" id="btn-next"><span class="btn-label">Save and
                    continue</span><span data-icon="arrowR"></span></button>
              </div>
            </form>
          </div>

          <!-- Form complete -->
          <div class="complete-card" id="complete-card" hidden>
            <svg class="check-draw" viewBox="0 0 52 52" aria-hidden="true">
              <circle cx="26" cy="26" r="24" />
              <path d="M15 27l7 7 15-16" />
            </svg>
            <p class="eyebrow">Application form complete</p>
            <h2>Nice work, <span id="complete-name">Jordan</span>.</h2>
            <p class="lede">Your personal, academic, and program details are saved. Next, upload your <span class="doc-total">7</span> required
              documents so admissions can review your application.</p>
            <div class="d-flex gap-2 flex-wrap justify-content-center">
              <button class="btn btn-brand" type="button" data-view="documents">Upload documents<span
                  data-icon="arrowR"></span></button>
              <button class="btn btn-ghost" type="button" id="edit-answers">Edit my answers</button>
            </div>
          </div>
          <footer class="page-footer">© 2026 Pamantasan ng Lungsod ng Pasig · Online Admission</footer>
        </section>

        <!-- ===================== DOCUMENTS ===================== -->
        <section class="view-section" id="view-documents">
          <div class="page-title page-title-inline" data-reveal>
            <div>
              <p class="eyebrow">Application requirements</p>
              <h1>Documents</h1>
              <p class="lede">Clear scans or photos · PDF, JPG, or PNG · up to 5 MB each. You can also drag a file onto a row.</p>
            </div>
          </div>

          <section class="docs-hero" data-reveal>
            <div class="ring ring-sm" id="docs-ring">
              <svg viewBox="0 0 120 120" aria-hidden="true">
                <circle class="ring-track" cx="60" cy="60" r="52" />
                <circle class="ring-fill" cx="60" cy="60" r="52" />
              </svg>
              <div class="ring-label"><strong class="count" id="docs-count">0</strong><span>of <span class="doc-total">7</span></span></div>
            </div>
            <div class="docs-hero-text">
              <h2 id="docs-headline">Documents</h2>
              <p id="docs-subline">Upload every requirement, then send them to admissions for review.</p>
            </div>
            <button class="btn btn-brand" type="button" id="submit-docs" disabled><span data-icon="send"></span><span
                class="btn-label">Submit for review</span></button>
          </section>

          <ul class="doc-list" id="doc-grid"></ul>

          <div class="info-note" data-reveal><span data-icon="help"></span>
            <p>After you submit, admissions checks each file. Verified documents get a green check; anything that
              needs resubmitting will be flagged here.</p>
          </div>
          <footer class="page-footer">© 2026 Pamantasan ng Lungsod ng Pasig · Online Admission</footer>
        </section>

        <!-- ===================== PROFILE (opened from the top bar) ===================== -->
        <section class="view-section" id="view-profile">
          <div class="page-title" data-reveal>
            <p class="eyebrow">Account settings</p>
            <h1>My profile</h1>
            <p class="lede">Keep your contact details up to date so admissions can reach you.</p>
          </div>

          <section class="profile-hero" data-reveal>
            <span class="avatar avatar-xl" id="profile-avatar">JM</span>
            <div class="profile-hero-text">
              <h2 id="profile-name">Applicant</h2>
              <p><span id="profile-email-label"></span> · Applicant</p>
            </div>
            <span class="status-pill draft" id="profile-status">Draft</span>
          </section>

          <div class="row g-3">
            <div class="col-lg-8" data-reveal>
              <section class="card-soft profile-card">
                <div class="panel-heading">
                  <div>
                    <h2>Contact details</h2>
                    <p id="profile-note">Changes here also update your application form.</p>
                  </div>
                </div>
                <form id="profile-form" class="mt-3" novalidate>
                  <div class="row g-3">
                    <div class="col-md-6"><label class="form-label" for="profile-first">First name</label><input
                        class="form-control" id="profile-first" autocomplete="given-name" maxlength="100" pattern="^\p{L}[\p{L} .'\-]*$" data-msg-pattern="Use letters only (spaces, hyphens, periods and apostrophes are fine)." required>
                      <div class="invalid-feedback" id="profile-first-error"></div>
                    </div>
                    <div class="col-md-6"><label class="form-label" for="profile-last">Last name</label><input
                        class="form-control" id="profile-last" autocomplete="family-name" maxlength="100" pattern="^\p{L}[\p{L} .'\-]*$" data-msg-pattern="Use letters only (spaces, hyphens, periods and apostrophes are fine)." required>
                      <div class="invalid-feedback" id="profile-last-error"></div>
                    </div>
                    <div class="col-md-6"><label class="form-label" for="profile-email">Email address</label><input
                        class="form-control" id="profile-email" type="email" autocomplete="email" readonly>
                      <div class="form-text">Your sign-in email. Ask the admission office to change it.</div>
                    </div>
                    <div class="col-md-6"><label class="form-label" for="profile-phone">Mobile number</label><input
                        class="form-control" id="profile-phone" type="tel" autocomplete="tel" maxlength="16" required
                        placeholder="+63 9XX XXX XXXX" pattern="^(\+63|0)\s?9\d{2}\s?\d{3}\s?\d{4}$"
                        data-msg-pattern="Use a PH mobile number, like +63 917 123 4567.">
                      <div class="invalid-feedback" id="profile-phone-error"></div>
                    </div>
                  </div>
                  <div class="form-actions">
                    <span class="save-note" id="profile-saved" role="status"></span>
                    <button class="btn btn-brand" type="submit" id="profile-submit"><span class="btn-label">Save
                        changes</span></button>
                  </div>
                </form>
              </section>
            </div>
            <div class="col-lg-4" data-reveal>
              <section class="card-soft profile-summary">
                <div class="panel-heading">
                  <div>
                    <h2>Application</h2>
                  </div>
                </div>
                <dl class="summary-list">
                  <div>
                    <dt>Application ID</dt>
                    <dd class="mono" id="profile-appid">—</dd>
                  </div>
                  <div>
                    <dt>Intake</dt>
                    <dd>AY 2027–2028 · 1st sem</dd>
                  </div>
                  <div>
                    <dt>First choice</dt>
                    <dd id="profile-program">Not chosen yet</dd>
                  </div>
                  <div>
                    <dt>Form sections</dt>
                    <dd id="profile-sections">0 of 3</dd>
                  </div>
                  <div>
                    <dt>Documents</dt>
                    <dd id="profile-docs">—</dd>
                  </div>
                </dl>
                <button class="btn btn-ghost w-100 mt-3 logout-button" type="button"><span
                    data-icon="logout"></span>Sign out</button>
              </section>
            </div>
          </div>
          <footer class="page-footer">© 2026 Pamantasan ng Lungsod ng Pasig · Online Admission</footer>
        </section>
      </div>
    </main>
  </div>

  <!-- User agreement (sign-up). Wording to be reviewed by the admission office and the data protection officer. -->
  <div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h2 class="modal-title-serif h5 mb-0" id="termsModalTitle">Terms and conditions</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body small">
          <p>By creating an account on the Pamantasan ng Lungsod ng Pasig (PLP) Online Admission portal, you agree to the
            following.</p>
          <h3 class="h6 mt-3">1. Your account</h3>
          <ul>
            <li>Create only one account, in your own name, with an email address and mobile number you use.</li>
            <li>Keep your password private. You are responsible for everything done with your account.</li>
            <li>Tell the admission office right away if you think someone else has used your account.</li>
          </ul>
          <h3 class="h6 mt-3">2. Truthful information</h3>
          <ul>
            <li>Everything you enter and every document you upload must be true, complete and your own.</li>
            <li>False, altered or borrowed information or documents can lead to your application being rejected, or
              your admission being cancelled even after enrollment.</li>
          </ul>
          <h3 class="h6 mt-3">3. The admission process</h3>
          <ul>
            <li>Creating an account or submitting an application does not guarantee admission. Slots are limited and
              decided by PLP's admission rules, entrance exam results and, for some programs, a college interview.</li>
            <li>Watch the deadlines shown in the portal. Late or incomplete applications may not be processed.</li>
            <li>PLP may contact you by email or text message about your application.</li>
          </ul>
          <h3 class="h6 mt-3">4. Proper use</h3>
          <ul>
            <li>Use the portal only for your own application. Do not try to access other people's records, overload the
              system, or get around its security checks.</li>
          </ul>
          <h3 class="h6 mt-3">5. Data privacy notice (Data Privacy Act of 2012, RA 10173)</h3>
          <ul>
            <li><strong>What we collect:</strong> your name, contact details, birth date, address, school records,
              uploaded documents, exam and interview results, and medical requirements.</li>
            <li><strong>Why:</strong> to process your application, schedule your exam and interview, release your
              result, and, if you are admitted, pass your records on for enrollment.</li>
            <li><strong>Who sees it:</strong> only authorized PLP admission, college and clinic staff, and PLP offices
              that handle enrollment. We do not sell your data.</li>
            <li><strong>How long:</strong> only as long as the admission process and PLP's records rules require; then
              it is securely deleted.</li>
            <li><strong>Your rights:</strong> you may ask to see, correct or delete your data, or object to its use, by
              contacting PLP's data protection officer through the admission office.</li>
            <li>If you are under 18, your parent or guardian must also agree to this notice in the application form.</li>
          </ul>
          <h3 class="h6 mt-3">6. Changes</h3>
          <p class="mb-0">PLP may update these terms. The portal will show the current version.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Close</button>
          <button type="button" class="btn btn-brand" id="terms-agree">I agree</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Submit confirmation -->
  <div class="modal fade" id="submitModal" tabindex="-1" aria-labelledby="submitModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-body">
          <span class="modal-icon" data-icon="send"></span>
          <h2 class="modal-title-serif" id="submitModalTitle">Send your application for review?</h2>
          <p>Your application form and all your documents go to the admissions team. You can't replace files while they're
            being reviewed.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Not yet</button>
          <button type="button" class="btn btn-brand" id="confirm-submit"><span class="btn-label">Submit for
              review</span></button>
        </div>
      </div>
    </div>
  </div>

  <!-- Medical requirements upload -->
  <div class="modal fade" id="medicalModal" tabindex="-1" aria-labelledby="medicalModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h2 class="modal-title fs-5" id="medicalModalTitle">Medical requirements</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" id="medical-form"></div>
      </div>
    </div>
  </div>

  <!-- Offer letter -->
  <div class="modal fade" id="offerModal" tabindex="-1" aria-labelledby="offerModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
      <div class="modal-content letter">
        <div class="modal-header">
          <div class="brand"><img class="brand-mark brand-logo" src="{{ asset('assets/shared/img/plp-logo.png') }}" alt="" aria-hidden="true">
        <span class="brand-text"><span class="brand-name">Pamantasan ng Lungsod ng Pasig</span><span class="brand-sub">Online Admission</span></span></div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" id="offer-letter"></div>
      </div>
    </div>
  </div>

  <div class="toast-container position-fixed bottom-0 end-0 p-3">
    <div id="portal-toast" class="toast portal-toast" role="status" aria-live="polite" aria-atomic="true">
      <div class="toast-body"><span class="toast-check" data-icon="check"></span><span id="toast-message"></span><button
          type="button" class="btn-close ms-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>
    </div>
  </div>

  <canvas id="confetti" aria-hidden="true"></canvas>
@endsection

@push('scripts')
  @if (\App\Services\Security\CaptchaVerifier::enabled())
    <script src="{{ \App\Services\Security\CaptchaVerifier::script() }}" async defer></script>
  @endif
  <script src="{{ \App\Support\Asset::v('assets/applicant/js/applicant.js') }}"></script>
@endpush
