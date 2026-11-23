import CourseDetailClient from './CourseDetailClient';

export function generateStaticParams() {
  return [{ id: '_' }];
}

export default function Page() {
  return <CourseDetailClient />;
}
