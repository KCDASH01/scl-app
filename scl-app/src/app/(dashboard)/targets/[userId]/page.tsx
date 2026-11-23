import UserTargetDetailClient from './UserTargetDetailClient';

export function generateStaticParams() {
  return [{ userId: '_' }];
}

export default function Page() {
  return <UserTargetDetailClient />;
}
