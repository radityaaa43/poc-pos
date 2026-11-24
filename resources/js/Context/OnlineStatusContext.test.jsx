import React from 'react';
import { act, render, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { OnlineStatusProvider, useOnlineStatus } from './OnlineStatusContext';

describe('OnlineStatusContext', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('starts from navigator.onLine', () => {
        vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false);

        const { result } = renderHook(() => useOnlineStatus(), { wrapper: OnlineStatusProvider });

        expect(result.current).toBe(false);
    });

    it('follows offline/online events', () => {
        vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(true);

        const { result } = renderHook(() => useOnlineStatus(), { wrapper: OnlineStatusProvider });
        expect(result.current).toBe(true);

        act(() => {
            window.dispatchEvent(new Event('offline'));
        });
        expect(result.current).toBe(false);

        act(() => {
            window.dispatchEvent(new Event('online'));
        });
        expect(result.current).toBe(true);
    });

    it('defaults to online outside the provider', () => {
        const { result } = renderHook(() => useOnlineStatus());

        expect(result.current).toBe(true);
    });

    it('stops listening after unmount', () => {
        vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(true);
        const removeSpy = vi.spyOn(window, 'removeEventListener');

        const { unmount } = render(<OnlineStatusProvider><span /></OnlineStatusProvider>);
        unmount();

        expect(removeSpy).toHaveBeenCalledWith('online', expect.any(Function));
        expect(removeSpy).toHaveBeenCalledWith('offline', expect.any(Function));
    });
});
