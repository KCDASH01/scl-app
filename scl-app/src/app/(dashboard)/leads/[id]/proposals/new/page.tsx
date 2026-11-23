import ProposalNewClient from './ProposalNewClient';

export function generateStaticParams() {
  return [{ id: '_' }];
}

export default function Page() {
  return <ProposalNewClient />;
}
