# HISTORY

Older rounds of work, moved here unchanged from docs/STATUS.md.

## Done: slice 1, 2026-11-25 (foundation)
- Rebuild as React + TypeScript (Vite) + plain PHP 8.3 + MySQL 8, structure of sld-orientation. Firebase removed.
- Google sign-in (server side, PKCE, `google_hosted_domains`, directory profile: matric/staff no, faculty, title).
- LinkedIn: compulsory link for students and staff (onboarding gate), follow-QIU tick, profile URL, PDPA consent;
  alumni-only LinkedIn sign-in; "Register as a QIU graduate" with CPDC approval and merge by matric number.
- Career profile with LinkedIn PDF import; optional GitHub link; People, person detail, Alumni tracker (employment
  rate, CSV, "make alumni" by matric list), Graduate sign-ups, Audit log; alumni job confirmation every 180 days.
- Hosting files: `Dockerfile` + `render.yaml` (Render test copy), `deploy/exabytes/` and `docs/RELEASE.md`.
