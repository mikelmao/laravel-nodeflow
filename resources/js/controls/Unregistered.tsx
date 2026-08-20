import type { FieldControlProps } from './types'

/**
 * What renders when nothing is registered for a field's type.
 *
 * Loud, and containing no input of any kind. 5.7 and 10 both insist on this:
 * falling back to a text box would silently turn a town picker into free text
 * that passes the server's `string` base rule and reaches a node as garbage. The
 * author would see a working-looking field, the host would see a green publish,
 * and the failure would surface days later inside a run.
 *
 * The type is named because the fix is one line in the host's `controls` prop
 * and they need to know which key to write.
 */
export function Unregistered({ field }: FieldControlProps) {
    return (
        <div role="alert" className="space-y-1 rounded-md border border-destructive/50 bg-destructive/5 p-2">
            <p className="text-xs font-medium text-destructive">
                {field.label} - no control for field type "{field.type}"
            </p>
            <p className="text-[11px] text-muted-foreground">
                Register one on the editor's <code>controls</code> prop: <code>{`controls={{ '${field.type}': MyControl }}`}</code>
            </p>
        </div>
    )
}
