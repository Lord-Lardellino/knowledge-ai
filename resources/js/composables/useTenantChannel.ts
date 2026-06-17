import { onMounted, onUnmounted } from 'vue'
import { echo } from '@/echo'

/**
 * useTenantChannel — ascolto real-time sul canale privato del tenant.
 *
 * Si iscrive a `tenant.{id}` e collega i listener passati (nome evento → callback),
 * pulendo tutto allo smontaggio del componente. Riutilizzabile in qualsiasi pagina.
 *
 *   useTenantChannel(auth.tenant?.id, {
 *     'document.updated': (e) => { ... },
 *     'triage.updated':   (e) => { ... },
 *   })
 *
 * I nomi evento combaciano con TenantBroadcast::broadcastAs() lato server.
 */
export function useTenantChannel (
    tenantId: number | null | undefined,
    listeners: Record<string, (payload: any) => void>,
): void {
    if (! tenantId) return

    const channelName = `tenant.${tenantId}`

    onMounted(() => {
        const channel = echo.private(channelName)
        for (const [event, cb] of Object.entries(listeners)) {
            channel.listen(`.${event}`, cb)
        }
    })

    onUnmounted(() => {
        echo.leave(channelName)
    })
}
