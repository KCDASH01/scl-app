import type { Metadata } from 'next';
import { Inter } from 'next/font/google';
import Providers from './providers';
import './globals.css';

const inter = Inter({ subsets: ['latin'] });

export const metadata: Metadata = {
  title: 'Sairudra Group of Institution — Admissions & Campus CRM',
  description: 'Manage enquiries, follow-ups, student admissions, fee collection, attendance, and hostel expenses.',
  icons: {
    icon: [{ url: '/sairudra-logo.jpg?v=2', type: 'image/jpeg' }],
    shortcut: '/sairudra-logo.jpg?v=2',
    apple: '/sairudra-logo.jpg?v=2',
  },
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en" className="h-full">
      <body className={`${inter.className} h-full antialiased`}>
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
