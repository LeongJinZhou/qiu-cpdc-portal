# STATUS

## Open
- Live on Render since 2026-11-26: https://qiu-cpdc-portal.onrender.com, Blueprint `qiu-cpdc` from repo
  qiu-cpdc-portal, branch `main` (every push deploys). Checked: health ok on the Aiven database, pages, headers,
  Google and LinkedIn redirects; the founder signed in and onboarded. Fixed live: Aiven's default SQL mode (ANSI)
  broke profile and workshop queries, so every connection now sets one SQL mode (CI runs MySQL in Aiven's mode);
  sessions moved to the database (table php_session), so a restart or sleep no longer signs people out; a save with
  an old CSRF token fetches the current one and retries once. Saved browser test e2e/every-page.spec.ts opens every
  menu page as superadmin, student and alumna. Not yet checked live: each module after these fixes (founder).
- The old Render service `qiu-cpdc-career-portal` (Next.js) is no longer used; its repo's master now holds this
  portal, the Next.js code is on branch `legacy-nextjs`. Removing that service is the founder's call.
- Security: an Aiven database password was pasted in chat on 2026-11-26. Reset it in Aiven and in Render.
- Exabytes upload when ready (docs/RELEASE.md section 4).
- Frontend unit tests (vitest, e.g. web/src/api.test.ts) do not run in CI yet.
- Founder, when wanted: `ANTHROPIC_API_KEY` in Render for interview feedback (paid; `AI_MODEL` claude-sonnet-5-5 in
  render.yaml). Without it the interview screen says "not set up yet".
- Email: Render has no sendmail, so `MAIL_DRIVER=log` there (supervisor links are only logged, not emailed). Exabytes
  uses `MAIL_DRIVER=mail` + `MAIL_FROM`. For Render: SMTP later.
- Render's free disk is lost on restart: uploaded CVs, certificate PDFs and project pictures vanish there. Before real
  users on Render: a persistent disk or object storage. Exabytes keeps files.
- Supervisor emails are flagged, not checked: CPDC sees a "Personal email: check" badge on free-mail or QIU addresses.
- Low findings left from the release review: project pictures keep EXIF data (re-encode with GD); no daily cap on
  assessment attempts or interview session starts; three route styles across slices (one routes file per module
  would be cleaner); duplicate date and test sign-in helpers; two h1 on /cv and /my-project; strike-through on done
  career-path steps; no hint on the disabled "Save my rating".
- Settings screen for `google_hosted_domains`, `student_employee_types`, `qiu_linkedin_page_url` (today in the DB).
- No screen to edit skill areas; no employer self-service; no email when an application status changes.
- LinkedIn photo links expire after weeks: refreshed at each LinkedIn sign-in; initials shown when broken.
- Local dev on Windows: `php -S` hangs; `tools/php-dev-server.mjs` replaces it. PHPStan's parallel workers can hang:
  `--debug` runs it serially.
- No production sweep yet ("production" before real students use it).
<!-- slice B open (begin) -->
- Slice B, after merging with slice A: pass slice A's "satisfaction survey still due" check to `HomeService`
  (`$satisfactionPending` closure in `HomeController`); until then the home to-do leaves that task out.
- Slice B migrations ran on MySQL 8.4 only here (no local MariaDB); check 0501, 0504, 0505 on MariaDB 10.11 before
  the Exabytes upload. Support units in the survey are named as in ACMS (SARD, SRD, SLD, FAD, ISO): CPDC may want
  their full names on screen.
<!-- slice B open (end) -->

- Slice A (ACMS port): the industry list is MSIC 2008's 21 sections, an assumption until CPDC sends the ACMS list.
  The satisfaction table and My applications scroll inside their panel on phones (no page overflow). Students on
  the short slice 2a internship see a "Complete placement form" notice.

## Next step
Founder sets up the Render database and keys; then the lead checks sign-in and each module live.

## Done: slice A of the ACMS port, 2026-11-26 (branch claude/slice-a-placement-e0dfb9, merged)
- Placement form on /logbook (Placement tab), change reason + old/new snapshot after submission, satisfaction survey
  (Survey tab); CPDC /internships gets Placements (filters, change history, Industry Visit CSV) and Satisfaction
  (per company, Industry Satisfaction CSV) tabs; jobs: extra details, state and specialization filters, Apply button,
  Contact employer, PDF brochure, closing date in My applications.
- Migrations 0500, 0502, 0503 (`placement.manage`); seed adds fake interns and an onboarded "graduate" student.
- Checks: 300 PHPUnit tests, PHPStan 8, PHPCS, ESLint, tsc, build, vitest, 10 Playwright tests (new
  e2e/placement-jobs.spec.ts at 1280px and 390px), gitleaks.

## Done: slice B of the ACMS port, 2026-11-26 (branch claude/slice-b-surveys-5fc760, merged)
- Home page for everyone (landing page): workshops with RSVP, to-do list, job applications, shortcuts.
- Self-assessments: 1-5 rating style, required written reflections, semester, printable A4 report; CPDC trait
  averages per faculty and semester and audited reading of reflections; seeded Student Characteristics assessment.
- Yearly University and Programme Survey: 7 ACMS sections in one PHP definition, wizard with server drafts, N/A on
  services, receipt; CPDC rounds, response rate per faculty and programme, means without N/A, comments, CSV.
- CV builder: folding sections, CGPA and intake, courses, awards (from verified learning), co-curricular,
  references, PDPA consent to share with partner employers; inline checks.
- CSV helper also escapes the full-width at sign. Seed: fake students, an open round, fake answers and results.
- Checks: 316 PHPUnit, PHPStan level 8, PHPCS, ESLint, TypeScript, 24 Vitest, build, all 7 Playwright specs incl.
  the new e2e/home-surveys-assessment.spec.ts at 1280px and 390px, gitleaks. Every new screen looked at in a
  browser at desktop and 390px (no horizontal overflow).

## Done: slices 2 and 3, 2026-11-26 (four parallel sessions, merged into main)
- 2a: internship logbook with supervisor approval by single-use emailed link (no login), CPDC intern progress; FYP
  showcase and talent feed; self-assessments with server scoring.
- 2b: employers and jobs with applications and CV PDF; skills self-rating with job gap view; learning records with
  CPDC verification.
- 3a: workshops with RSVP, waitlist under a row lock, check-in code and attendance CSV; CV builder saved on the
  server (A4 print); career paths per programme.
- 3b: opt-in alumni directory, mentoring requests and matches; interview practice with AI feedback (server only,
  fake driver locally, daily cap).
- Merge: grouped side menu (My career / Opportunities / CPDC; drawer under 960px with Escape and focus handling);
  seed clears every person-owned table; e2e specs share `e2e/onboarding.ts`; local seed raises the sign-in limit.
- Release review (code, security, UX) fixed: assessment attempt locks against edits; supervisor decision audited
  (link id only) and the student notice sent after the commit; personal supervisor email flag; duplicate-key check
  on job applications; AI answer cannot break its fence; render.yaml mail and AI settings; employer and job lists as
  cards on phones; page headings kept on load errors; exact current-menu match; "Assessment builder" label.
- Checks: 272 PHPUnit tests, PHPStan level 8, ESLint, PHPCS, TypeScript, build, 5 Playwright specs, gitleaks.

## Done: slice 1, 2026-11-25 (foundation)
- Rebuild as React + TypeScript (Vite) + plain PHP 8.3 + MySQL 8, structure of sld-orientation. Firebase removed.
- Google sign-in (server side, PKCE, `google_hosted_domains`, directory profile: matric/staff no, faculty, title).
- LinkedIn: compulsory link for students and staff (onboarding gate), follow-QIU tick, profile URL, PDPA consent;
  alumni-only LinkedIn sign-in; "Register as a QIU graduate" with CPDC approval and merge by matric number.
- Career profile with LinkedIn PDF import; optional GitHub link; People, person detail, Alumni tracker (employment
  rate, CSV, "make alumni" by matric list), Graduate sign-ups, Audit log; alumni job confirmation every 180 days.
- Hosting files: `Dockerfile` + `render.yaml` (Render test copy), `deploy/exabytes/` and `docs/RELEASE.md`.
