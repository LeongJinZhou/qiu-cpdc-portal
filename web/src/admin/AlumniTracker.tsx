// The alumni tracker (docs/SPEC.md rules 10, 13, 14): employment rate per faculty and year, the list with each
// graduate's LinkedIn, CSV export, and turning students into alumni by matric number.
import { Download, GraduationCap, Search } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { apiGet, apiWrite, useLoad, type Paged } from '../api';
import type { Me } from '../auth';
import { PageHead } from '../shell/Shell';
import { STATUS_LABEL, dateLabel, type EmploymentStatus } from '../profile/types';
import { Empty, LoadError, Notice, PersonName, SkeletonLines, Spinner, errorText } from '../ui/parts';
import { Pager } from './Pager';
import './admin.css';

interface AlumniRow {
  id: number;
  display_name: string;
  matric_no: string | null;
  picture_url: string | null;
  faculty: string | null;
  programme: string | null;
  graduation_year: number | null;
  employment_status: EmploymentStatus;
  current_title: string | null;
  current_company: string | null;
  job_confirmed_at: string | null;
  linkedin_url: string | null;
  linkedin_linked: boolean;
}

interface StatRow {
  faculty: string;
  graduation_year: number | null;
  alumni: number;
  known: number;
  in_work_or_study: number;
  unknown: number;
  linkedin_linked: number;
  rate: number | null;
}

type Filters = { q: string; faculty: string; year: string; status: string; linkedin: string };
const NO_FILTERS: Filters = { q: '', faculty: '', year: '', status: '', linkedin: '' };

export function AlumniTracker({ me, go }: { me: Me; go: (to: string) => void }) {
  const [draft, setDraft] = useState<Filters>(NO_FILTERS);
  const [filters, setFilters] = useState<Filters>(NO_FILTERS);
  const [page, setPage] = useState(1);
  const query = { ...filters, page, per_page: 25 };
  const list = useLoad(`alumni:${JSON.stringify(query)}`, () => apiGet<Paged<AlumniRow>>('/api/alumni', query), 'alumni');
  const stats = useLoad('alumni-stats', () => apiGet<{ rows: StatRow[] }>('/api/alumni/stats'));
  const faculties = [...new Set((stats.data?.rows ?? []).map((r) => r.faculty))].sort();

  const apply = (event: FormEvent) => {
    event.preventDefault();
    setPage(1);
    setFilters(draft);
  };
  const exportUrl = `/api/alumni/export?${new URLSearchParams(Object.entries(filters).filter(([, v]) => v !== '')).toString()}`;

  return (
    <>
      <PageHead
        title="Alumni tracker"
        actions={
          <a className="btn" href={exportUrl} download>
            <Download size={16} aria-hidden="true" /> Download CSV
          </a>
        }
      >
        Where QIU graduates are now. Every view and download is recorded in the audit log.
      </PageHead>

      <section className="panel panel-pad" aria-labelledby="rate-h" style={{ marginBottom: 20 }}>
        <h2 id="rate-h" style={{ marginBottom: 12 }}>Employment rate</h2>
        {stats.loading && !stats.data && <SkeletonLines lines={3} />}
        {stats.error && <LoadError error={stats.error} onRetry={stats.reload} />}
        {stats.data && stats.data.rows.length === 0 && <Empty icon={GraduationCap} title="No alumni yet">Turn students into alumni below.</Empty>}
        {stats.data && stats.data.rows.length > 0 && <RateTable rows={stats.data.rows} />}
        <p className="small muted" style={{ marginTop: 10 }}>
          Rate = employed, self-employed or in further study, out of alumni whose status is known.
        </p>
      </section>

      <section className="panel panel-pad" aria-labelledby="list-h">
        <h2 id="list-h" className="visually-hidden">Alumni</h2>
        <form className="toolbar" onSubmit={apply} style={{ marginBottom: 16 }}>
          <div className="field" style={{ flex: '2 1 220px' }}>
            <label htmlFor="al-q">Search</label>
            <input id="al-q" className="input" placeholder="Name, matric no, company or job" value={draft.q} onChange={(e) => setDraft({ ...draft, q: e.target.value })} />
          </div>
          <div className="field">
            <label htmlFor="al-fac">Faculty</label>
            <select id="al-fac" className="select" value={draft.faculty} onChange={(e) => setDraft({ ...draft, faculty: e.target.value })}>
              <option value="">All</option>
              {faculties.map((f) => <option key={f} value={f}>{f}</option>)}
            </select>
          </div>
          <div className="field" style={{ flex: '0 1 120px', minWidth: 100 }}>
            <label htmlFor="al-year">Year</label>
            <input id="al-year" className="input" inputMode="numeric" placeholder="Any" value={draft.year} onChange={(e) => setDraft({ ...draft, year: e.target.value.replace(/\D/g, '').slice(0, 4) })} />
          </div>
          <div className="field">
            <label htmlFor="al-status">Status</label>
            <select id="al-status" className="select" value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}>
              <option value="">All</option>
              {Object.entries(STATUS_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </div>
          <div className="field">
            <label htmlFor="al-li">LinkedIn</label>
            <select id="al-li" className="select" value={draft.linkedin} onChange={(e) => setDraft({ ...draft, linkedin: e.target.value })}>
              <option value="">All</option>
              <option value="linked">Linked</option>
              <option value="missing">Not linked</option>
            </select>
          </div>
          <button type="submit" className="btn btn-primary"><Search size={16} aria-hidden="true" /> Show</button>
        </form>
        {list.error && <LoadError error={list.error} onRetry={list.reload} />}
        {list.loading && !list.data && <SkeletonLines lines={5} />}
        {list.data && list.data.items.length === 0 && <Empty icon={GraduationCap} title="No alumni match">Try other filters.</Empty>}
        {list.data && list.data.items.length > 0 && (
          <div className="table-wrap" aria-busy={list.loading}>
            <table className="table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Faculty · Year</th>
                  <th>Now</th>
                  <th>Confirmed</th>
                  <th><span className="visually-hidden">Open</span></th>
                </tr>
              </thead>
              <tbody>
                {list.data.items.map((a) => (
                  <tr key={a.id}>
                    <td>
                      <PersonName name={a.display_name} linkedinUrl={a.linkedin_url} photo={a.picture_url} />
                      {!a.linkedin_linked && <span className="badge badge-warn" style={{ marginLeft: 8 }}>No LinkedIn</span>}
                    </td>
                    <td className="small">
                      {a.faculty ?? '-'}
                      <div className="muted num">{a.graduation_year ?? 'Year not set'}</div>
                    </td>
                    <td className="small">
                      <StatusBadge status={a.employment_status} />
                      {(a.current_title || a.current_company) && (
                        <div style={{ marginTop: 4 }}>{[a.current_title, a.current_company].filter(Boolean).join(', ')}</div>
                      )}
                    </td>
                    <td className="small muted num">{dateLabel(a.job_confirmed_at)}</td>
                    <td>
                      <a className="btn btn-small" href={`/people/${a.id}`} onClick={(e) => { e.preventDefault(); go(`/people/${a.id}`); }}>
                        View<span className="visually-hidden"> {a.display_name}</span>
                      </a>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {list.data && <Pager page={page} perPage={list.data.per_page} total={list.data.total} onPage={setPage} />}
      </section>

      <ConvertList me={me} onDone={() => { list.reload(); stats.reload(); }} />
    </>
  );
}

function RateTable({ rows }: { rows: StatRow[] }) {
  return (
    <div className="table-wrap">
      <table className="table">
        <thead>
          <tr>
            <th>Faculty</th>
            <th className="num">Year</th>
            <th className="num">Alumni</th>
            <th className="num">In work or study</th>
            <th className="num">Status unknown</th>
            <th className="num">LinkedIn linked</th>
            <th>Rate</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={`${r.faculty}-${r.graduation_year}`}>
              <td>{r.faculty}</td>
              <td className="num">{r.graduation_year ?? '-'}</td>
              <td className="num">{r.alumni}</td>
              <td className="num">{r.in_work_or_study}</td>
              <td className="num">{r.unknown}</td>
              <td className="num">{r.linkedin_linked}</td>
              <td>
                {r.rate === null ? (
                  <span className="muted small">No data</span>
                ) : (
                  <span className="rate">
                    <span className="rate-bar" aria-hidden="true"><span style={{ width: `${Math.round(r.rate * 100)}%` }} /></span>
                    <span className="num">{Math.round(r.rate * 100)}%</span>
                  </span>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export function StatusBadge({ status }: { status: EmploymentStatus }) {
  const tone = status === 'employed' || status === 'self_employed' || status === 'further_study' ? 'badge-ok' : status === 'seeking' ? 'badge-warn' : '';
  return <span className={`badge ${tone}`}>{STATUS_LABEL[status]}</span>;
}

/** Paste "matric number, graduation year" lines to turn those students into alumni (rule 10). */
function ConvertList({ me, onDone }: { me: Me; onDone: () => void }) {
  const [text, setText] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [results, setResults] = useState<{ matric_no: string; result: string }[] | null>(null);

  async function convert(event: FormEvent) {
    event.preventDefault();
    const rows = text
      .split(/\r?\n/)
      .map((line) => line.split(/[,;\t]/).map((p) => p.trim()))
      .filter((parts) => parts[0])
      .map(([matric_no, year]) => ({ matric_no, graduation_year: Number(year) }));
    const bad = rows.findIndex((r) => !Number.isInteger(r.graduation_year) || r.graduation_year < 1990);
    if (rows.length === 0 || bad >= 0) {
      setError(rows.length === 0 ? 'Paste at least one line.' : `Line ${bad + 1} needs a graduation year, like A12345, 2026.`);
      return;
    }
    setSaving(true);
    setError(null);
    try {
      const answer = await apiWrite<{ results: { matric_no: string; result: string }[] }>('POST', '/api/alumni/convert', { rows }, me.csrf_token);
      setResults(answer.results);
      onDone();
    } catch (failure) {
      setError(errorText(failure));
    } finally {
      setSaving(false);
    }
  }

  const count = (kind: string) => results?.filter((r) => r.result === kind).length ?? 0;
  return (
    <form className="panel panel-pad stack" onSubmit={convert} style={{ marginTop: 20 }} aria-labelledby="convert-h" noValidate>
      <h2 id="convert-h">Graduates of a convocation</h2>
      <p className="small muted">
        One student per line: matric number, graduation year. They become alumni and keep everything they entered.
        Students without LinkedIn are marked: they cannot sign in after their QIU account closes.
      </p>
      {error && <Notice tone="error">{error}</Notice>}
      {results && (
        <Notice tone={count('not_found') + count('not_student') + count('failed') > 0 ? 'warn' : 'ok'}>
          {count('converted')} now alumni. {count('no_linkedin') > 0 && `${count('no_linkedin')} became alumni but have no LinkedIn. `}
          {count('not_found') > 0 && `Not found: ${results.filter((r) => r.result === 'not_found').map((r) => r.matric_no).join(', ')}. `}
          {count('not_student') > 0 && `${count('not_student')} are not students.`}
        </Notice>
      )}
      <div className="field">
        <label htmlFor="convert-text">Matric numbers and years</label>
        <textarea id="convert-text" className="textarea num" placeholder={'A12345, 2026\nA12346, 2026'} value={text} onChange={(e) => setText(e.target.value)} />
      </div>
      <button type="submit" className="btn btn-primary" disabled={saving} style={{ justifySelf: 'start' }}>
        {saving && <Spinner />}Make alumni
      </button>
    </form>
  );
}
