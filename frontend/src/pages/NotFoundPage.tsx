import { Link } from 'react-router-dom';
import { Empty } from '@/components/ui';

export default function NotFoundPage() {
  return (
    <Empty title="Page not found">
      <Link to="/" className="btn btn--primary">
        Back home
      </Link>
    </Empty>
  );
}
