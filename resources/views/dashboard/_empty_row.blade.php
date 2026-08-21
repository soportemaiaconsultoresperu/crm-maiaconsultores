{{--
    Reusable empty-state row for dashboard tables when no rows are returned
    by the service. Keeps the table layout stable (no colspan hacks).
--}}
<tr class="text-secondary" data-testid="empty-row">
    <td colspan="{{ $colspan ?? 1 }}" class="text-center small py-3">
        {{ $slot ?? 'Sin datos para los filtros aplicados.' }}
    </td>
</tr>