{{-- B12-UI — PR 4 / Stage 4 — ActionEditor host view.
     Renders one of the 11 per-type widgets via @switch based on
     getActionTypeProperty(). retry_policy_json MUST NOT appear in this view. --}}
<div>
    @php
        $widgetClass = $this->widgetClass();
        $payload = $this->widgetPayload();
    @endphp
    @livewire($widgetClass, [
        'actionIndex' => $actionIndex,
        'payload' => $payload,
        'editorUserId' => $editorUserId,
        'key' => 'action-editor-' . $actionIndex . '-' . $this->getActionTypeProperty(),
    ])
</div>
