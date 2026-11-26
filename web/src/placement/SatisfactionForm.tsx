// The intern's post-internship satisfaction survey: two yes/no questions, a 1-5 rating and optional feedback.
import { useState, type FormEvent } from 'react';
import { apiWrite } from '../api';
import { dateLabel } from '../profile/types';
import { errorText, Notice, Spinner } from '../ui/parts';
import { RATING_LABEL, type MyPlacement, type Satisfaction } from './types';

type YesNo = '' | 'yes' | 'no';

export function SatisfactionForm({
  company,
  saved,
  csrf,
  onSaved,
}: {
  company: string;
  saved: Satisfaction | null;
  csrf: string;
  onSaved: (mine: MyPlacement) => void;
}) {
  const yn = (v: boolean | undefined): YesNo => (v === undefined ? '' : v ? 'yes' : 'no');
  const [recommend, setRecommend] = useState<YesNo>(yn(saved?.recommend_juniors));
  const [offered, setOffered] = useState<YesNo>(yn(saved?.offered_job));
  const [rating, setRating] = useState(saved?.overall_rating ?? 0);
  const [feedback, setFeedback] = useState(saved?.feedback ?? '');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);
  const ready = recommend !== '' && offered !== '' && rating > 0;

  async function save(event: FormEvent) {
    event.preventDefault();
    if (!ready) {
      setMessage({ tone: 'error', text: 'Answer the three questions first. Feedback is optional.' });
      return;
    }
    setBusy(true);
    setMessage(null);
    try {
      const mine = await apiWrite<MyPlacement>('PUT', '/api/placement/satisfaction', {
        recommend_juniors: recommend === 'yes',
        overall_rating: rating,
        offered_job: offered === 'yes',
        feedback: feedback.trim(),
      }, csrf);
      onSaved(mine);
      setMessage({ tone: 'ok', text: 'Thank you. Your answers are saved; you can change them later.' });
    } catch (failure) {
      setMessage({ tone: 'error', text: errorText(failure) });
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="stack" onSubmit={save} noValidate>
      {saved && <p className="small muted">Last saved {dateLabel(saved.updated_at)}. Change any answer and save again.</p>}
      <YesNoQuestion
        name="recommend"
        question={`Would you recommend QIU juniors to pursue an internship at ${company}?`}
        value={recommend}
        onChange={setRecommend}
      />
      <fieldset className="pl-question">
        <legend>How would you rate your internship overall?</legend>
        <div className="pl-rating">
          {[1, 2, 3, 4, 5].map((n) => (
            <label key={n} className="pl-rating-option" data-checked={rating === n}>
              <input type="radio" name="rating" value={n} checked={rating === n} onChange={() => setRating(n)} />
              <span className="num">{n}</span>
              <span className="small">{RATING_LABEL[n]}</span>
            </label>
          ))}
        </div>
      </fieldset>
      <YesNoQuestion name="offered" question="Were you offered a job by the internship company?" value={offered} onChange={setOffered} />
      <div className="field">
        <label htmlFor="sv-feedback">Feedback (optional)</label>
        <textarea id="sv-feedback" className="textarea" rows={4} maxLength={2000} value={feedback} onChange={(e) => setFeedback(e.target.value)} placeholder="What went well, and what juniors should know." />
        <span className="hint num">{feedback.length} / 2000</span>
      </div>
      {message && <Notice tone={message.tone}>{message.text}</Notice>}
      <div className="row">
        <button type="submit" className="btn btn-primary" disabled={busy}>
          {busy && <Spinner />} {saved ? 'Save my answers' : 'Send my answers'}
        </button>
      </div>
    </form>
  );
}

function YesNoQuestion({ name, question, value, onChange }: { name: string; question: string; value: YesNo; onChange: (v: YesNo) => void }) {
  return (
    <fieldset className="pl-question">
      <legend>{question}</legend>
      <div className="row">
        {(['yes', 'no'] as const).map((v) => (
          <label key={v} className="pl-choice" data-checked={value === v}>
            <input type="radio" name={name} value={v} checked={value === v} onChange={() => onChange(v)} />
            {v === 'yes' ? 'Yes' : 'No'}
          </label>
        ))}
      </div>
    </fieldset>
  );
}
