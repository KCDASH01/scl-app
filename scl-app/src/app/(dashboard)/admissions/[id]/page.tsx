import AdmissionDetailClient from './AdmissionDetailClient';

export function generateStaticParams() {
  return [{ id: '_' }];
}

export default function Page() {
  return <AdmissionDetailClient />;
}
