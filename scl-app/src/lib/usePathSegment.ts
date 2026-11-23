'use client';

import { usePathname } from 'next/navigation';

/**
 * Reads a URL segment from the real browser path rather than route params.
 * The static export builds each dynamic page once with a "_" placeholder and
 * the server rewrites real IDs onto it, so useParams() would return "_".
 */
export function usePathSegment(index: number): string {
  const pathname = usePathname() ?? '';
  return pathname.split('/').filter(Boolean)[index] ?? '';
}
