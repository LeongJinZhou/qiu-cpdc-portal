// The signed-in person's own applications and their status.
import { Download, Inbox } from 'lucide-react';
import { apiGet, useLoad } from '../api';
import type { Me } from '../auth';
import { Empty, LoadError, SkeletonLines } from '../ui/parts';
import { dateLabel } from '../profile/types';
import { APPLICATION_LABEL, TYPE_LABEL, applicationTone, dayLabel, type MyApplication } from './types';

export function MyApplications({ me, onOpen }: { me: Me; onOpen: (jobId: number) => void }) {
  const list = useLoad(`my-applications:${me.user.id}`, () => apiGet<{ items: MyApplication[] }>('/api/applications/mine'));

  return (
    <section className="panel panel-pad" aria-label="My applications">
      {list.error && <LoadError error={list.error} onRetry={list.reload} />}
      {list.loading && !list.data && <SkeletonLines lines={4} />}
      {list.data && list.data.items.length === 0 && (
        <Empty icon={Inbox} title="No applications yet">Jobs you apply for appear here with their status.</Empty>
      )}
      {list.data && list.data.items.length > 0 && (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>Job</th>
                <th>Status</th>
                <th>Applied</th>
                <th>Closes</th>
                <th><span className="visually-hidden">CV</span></th>
              </tr>
            </thead>
            <tbody>
              {list.data.items.map((a) => (
                <tr key={a.id}>
                  <td>
                    <button type="button" className="link-button" onClick={() => onOpen(a.job_id)}>{a.title}</button>
                    <div className="small muted">{a.employer_name} · {TYPE_LABEL[a.job_type]}</div>
                  </td>
                  <td>
                    <span className={applicationTone(a.status)}>{APPLICATION_LABEL[a.status]}</span>
                    <div className="small muted num">since {dateLabel(a.status_changed_at)}</div>
                  </td>
                  <td className="small num">{dateLabel(a.created_at)}</td>
                  <td className="small num">{dayLabel(a.closing_date)}</td>
                  <td>
                    {a.cv_name && (
                      <a className="btn btn-quiet btn-small" href={`/api/applications/${a.id}/cv`} download aria-label={`Download the CV sent for ${a.title}`}>
                        <Download size={15} aria-hidden="true" />
                      </a>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
