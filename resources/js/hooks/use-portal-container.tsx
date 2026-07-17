import { createContext, useContext } from 'react';

/**
 * The DOM node Radix portal-rendered content (dialogs, popovers, dropdowns)
 * should mount into, instead of its default `document.body`.
 *
 * The customer portal is always light regardless of the merchant dashboard's
 * theme (see `.portal` in app.css) — but `document.body` sits outside the
 * `.portal`-scoped subtree, so anything Radix portals there falls back to
 * the page's real theme instead of inheriting the portal's light-mode CSS
 * variables. Rendering into a node that *is* `.portal` fixes that by
 * inheritance, no theme detection required.
 *
 * `null` (the default, and the value everywhere outside the customer portal)
 * means "no override" — Radix's own `document.body` fallback applies, so
 * every merchant-dashboard dialog is unaffected.
 */
const PortalContainerContext = createContext<HTMLElement | null>(null);

export const PortalContainerProvider = PortalContainerContext.Provider;

export function usePortalContainer(): HTMLElement | null {
    return useContext(PortalContainerContext);
}
