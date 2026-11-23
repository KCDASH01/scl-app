import LeadDetailClient from './LeadDetailClient';

export function generateStaticParams() {
  return [{ id: '_' }];
}

export default function Page() {
  return <LeadDetailClient />;
}
