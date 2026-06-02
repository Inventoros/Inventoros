import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

/**
 * Merge Tailwind classes safely, dedupe conflicting utilities.
 * Used by every UI primitive so consumers can override classes via :class.
 */
export function cn(...inputs) {
    return twMerge(clsx(inputs));
}
