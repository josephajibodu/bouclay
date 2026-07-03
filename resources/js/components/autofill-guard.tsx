/**
 * Chrome pairs a password field with the nearest preceding text input in
 * the same form and offers to fill both with a saved login — `autoComplete`
 * values on the real fields don't stop this. Placing genuinely fillable
 * decoy fields first gives the browser's heuristic something harmless to
 * latch onto instead. They're excluded from FormData-based submits by
 * using names the backend never reads.
 */
export default function AutofillGuard() {
    return (
        <div
            aria-hidden="true"
            style={{
                position: 'absolute',
                width: 0,
                height: 0,
                overflow: 'hidden',
                opacity: 0,
                pointerEvents: 'none',
            }}
        >
            <input
                type="text"
                name="_autofill_decoy_username"
                autoComplete="username"
                tabIndex={-1}
            />
            <input
                type="password"
                name="_autofill_decoy_password"
                autoComplete="new-password"
                tabIndex={-1}
            />
        </div>
    );
}
