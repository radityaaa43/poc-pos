import React from 'react';
import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ThemeSwitcherProvider, useTheme } from './ThemeSwitcherContext';

const renderTheme = () => renderHook(() => useTheme(), { wrapper: ThemeSwitcherProvider });

describe('ThemeSwitcherContext', () => {
    beforeEach(() => {
        localStorage.clear();
        document.body.className = '';
        document.documentElement.className = '';
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('reads darkMode from storage', () => {
        localStorage.setItem('darkMode', 'true');

        const { result } = renderTheme();

        expect(result.current.darkMode).toBe(true);
        expect(document.body).toHaveClass('dark');
    });

    it('defaults to light and stores "false"', () => {
        const { result } = renderTheme();

        expect(result.current.darkMode).toBe(false);
        expect(document.body).not.toHaveClass('dark');
        expect(localStorage.getItem('darkMode')).toBe('false');
    });

    it('themeSwitcher toggles class and storage', () => {
        const { result } = renderTheme();

        act(() => result.current.themeSwitcher());
        expect(result.current.darkMode).toBe(true);
        expect(document.body).toHaveClass('dark');
        expect(localStorage.getItem('darkMode')).toBe('true');

        act(() => result.current.themeSwitcher());
        expect(result.current.darkMode).toBe(false);
        expect(document.body).not.toHaveClass('dark');
        expect(localStorage.getItem('darkMode')).toBe('false');
    });

    it('no-transition is removed after a tick', () => {
        vi.useFakeTimers();

        renderTheme();
        expect(document.documentElement).toHaveClass('no-transition');

        act(() => {
            vi.advanceTimersByTime(1);
        });
        expect(document.documentElement).not.toHaveClass('no-transition');
    });
});
