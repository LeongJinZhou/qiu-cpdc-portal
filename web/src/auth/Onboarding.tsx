// First sign-in steps (docs/SPEC.md rules 4 and 6): link LinkedIn, follow QIU, public profile link, consent.
import { Check, ExternalLink } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { ApiFailure, apiWrite } from '../api';
import type { Me } from '../auth';
import { Notice, Spinner, errorText } from '../ui/parts';
import { LINK_RESULTS } from './linkedinResult';
import { LinkedInMark } from './ProviderMarks';
import './auth.css';

const LINKEDIN_URL = /^(https?:\/\/)?([a-z]{2,3}\.|www\.)?linkedin\.com\/in\/[A-Za-z0-9\-_%]{3,100}\/?(\?.*)?$/i;

export function Onboarding({ me, linkResult, onDone }: { me: Me; linkResult: string | null; onDone: () => void }) {
  const state = me.onboarding;
  const [followed, setFollowed] = useState(state.follow_confirmed);
  // LinkedIn tells no app who follows a page, so the tick unlocks only after the QIU page was opened from here.
  const [openedQiu, setOpenedQiu] = useState(state.follow_confirmed);
  const [profileUrl, setProfileUrl] = useState(state.profile_url ?? '');
  const [consent, setConsent] = useState(state.consent_given);
  const [urlError, setUrlError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [failure, setFailure] = useState<string | null>(null);
  const result = linkResult ? LINK_RESULTS[linkResult] : undefined;
  const linked = state.linkedin_linked;
  const urlDone = LINKEDIN_URL.test(profileUrl.trim());
  const ready = linked && followed && consent && profileUrl.trim() !== '';

  async function finish(event: FormEvent) {
    event.preventDefault();
    setFailure(null);
    if (!LINKEDIN_URL.test(profileUrl.trim())) {
      setUrlError('Paste the address of your LinkedIn profile, like https://www.linkedin.com/in/your-name.');
      return;
    }
    setUrlError(null);
    setSaving(true);
    try {
      await apiWrite('POST', '/api/onboarding/complete', {
        profile_url: profileUrl.trim(),
        follow_confirmed: true,
        consent: true,
      }, me.csrf_token);
      onDone();
    } catch (error) {
      if (error instanceof ApiFailure && error.field === 'profile_url') setUrlError(error.detail);
      else setFailure(errorText(error));
      setSaving(false);
    }
  }

  return (
    <main className="auth">
      <form className="auth-card wide" onSubmit={finish} noValidate>
        <img className="auth-logo" src="/brand/qiu-logo-horizontal-dark.png" alt="Quest International University" />
        <div className="auth-head">
          <h1>Welcome, {me.user.display_name.split(' ')[0]}</h1>
          <p>
            Before you start, link your LinkedIn. It keeps your career profile up to date and lets CPDC stay in touch
            with you after you graduate.
          </p>
        </div>
        {result && <Notice tone={result.tone}>{result.text}</Notice>}
        {failure && <Notice tone="error">{failure}</Notice>}
        <ol className="panel panel-pad steps">
          <li className="step" data-done={linked} data-current={!linked}>
            <span className="step-dot">{linked ? <Check size={16} aria-label="Done" /> : '1'}</span>
            <div className="step-body">
              <h2>Link your LinkedIn account</h2>
              <p>
                {linked
                  ? 'Done. You signed in to LinkedIn and it is now linked to your QIU account.'
                  : 'Sign in to LinkedIn once. We receive your name, photo and LinkedIn email, nothing else.'}
              </p>
              <div className="row">
                <a className={linked ? 'btn btn-small' : 'btn btn-primary'} href="/api/auth/linkedin/start?mode=link&return=/onboarding">
                  <LinkedInMark />
                  {linked ? 'Use a different LinkedIn account' : 'Sign in with LinkedIn'}
                </a>
              </div>
              {!linked && <p className="small muted">No LinkedIn yet? Create a free account at linkedin.com first, then come back.</p>}
            </div>
          </li>
          <li className="step" data-done={followed} data-current={linked && !followed}>
            <span className="step-dot">{followed ? <Check size={16} aria-label="Done" /> : '2'}</span>
            <div className="step-body">
              <h2>Follow QIU on LinkedIn</h2>
              <p>CPDC shares jobs, internships and events there.</p>
              <div className="row">
                <a
                  className={openedQiu ? 'btn btn-small' : 'btn btn-small btn-primary'}
                  href={me.qiu_linkedin_url}
                  target="_blank"
                  rel="noopener noreferrer"
                  onClick={() => setOpenedQiu(true)}
                >
                  Open QIU on LinkedIn and click Follow
                  <ExternalLink size={14} aria-hidden="true" />
                </a>
              </div>
              <label className="check">
                <input type="checkbox" checked={followed} disabled={!openedQiu} onChange={(e) => setFollowed(e.target.checked)} />
                <span>I follow Quest International University on LinkedIn.</span>
              </label>
              {!openedQiu && <p className="small muted">Open the QIU page first, then tick this.</p>}
            </div>
          </li>
          <li className="step" data-done={urlDone} data-current={linked && followed && !urlDone}>
            <span className="step-dot">{urlDone ? <Check size={16} aria-label="Done" /> : '3'}</span>
            <div className="step-body">
              <h2>Your LinkedIn profile link</h2>
              <p>LinkedIn does not share this link with us, so paste it here. Staff and CPDC open it from your name.</p>
              <div className="row">
                {/* linkedin.com/in/me opens the signed-in person's own profile, so the address is ready to copy. */}
                <a className="btn btn-small" href="https://www.linkedin.com/in/me/" target="_blank" rel="noopener noreferrer">
                  Open my LinkedIn profile
                  <ExternalLink size={14} aria-hidden="true" />
                </a>
              </div>
              <div className="field">
                <label htmlFor="profile-url">Profile link</label>
                <input
                  id="profile-url"
                  className="input"
                  inputMode="url"
                  autoComplete="url"
                  placeholder="https://www.linkedin.com/in/your-name"
                  value={profileUrl}
                  aria-invalid={urlError !== null}
                  aria-describedby="profile-url-hint"
                  onChange={(e) => setProfileUrl(e.target.value)}
                />
                {urlError ? (
                  <span className="error" id="profile-url-hint">{urlError}</span>
                ) : (
                  <span className="hint" id="profile-url-hint">
                    Copy the address from the browser bar of the page that opens, then paste it here. In the app: Share,
                    then Copy link.
                  </span>
                )}
              </div>
            </div>
          </li>
          <li className="step" data-done={consent} data-current={linked && followed && urlDone && !consent}>
            <span className="step-dot">{consent ? <Check size={16} aria-label="Done" /> : '4'}</span>
            <div className="step-body">
              <h2>Your data</h2>
              <div className="consent-text">
                <p>
                  QIU CPDC keeps your QIU directory details, your LinkedIn name, photo, email and profile link, and the
                  career details you add. CPDC uses them to support your career and, after you graduate, to keep in
                  touch and report how QIU graduates are doing.
                </p>
                <p>
                  Only you, CPDC and the portal administrator see your full profile. You can update it at any time. <a href="/privacy" target="_blank" rel="noopener noreferrer">Read more</a>
                </p>
              </div>
              <label className="check">
                <input type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} />
                <span>I agree that CPDC keeps and uses my career profile as described.</span>
              </label>
            </div>
          </li>
        </ol>
        <button type="submit" className="btn btn-primary btn-block" disabled={!ready || saving}>
          {saving && <Spinner />}
          {saving ? 'Saving' : 'Finish and open my profile'}
        </button>
        {!linked && <p className="small muted">Step 1 must be done before you can finish.</p>}
      </form>
    </main>
  );
}
