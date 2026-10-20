{{--
    Client-side narrowing of a topic select to the chosen pillar (S12).
    Mark the topic <select> with data-topic-filter="<pillar select id>" and each
    option with data-pillar-id. Presentation only: the server still validates
    that the topic belongs to the pillar.
--}}
@once
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                document.querySelectorAll('select[data-topic-filter]').forEach((topic) => {
                    const pillar = document.getElementById(topic.dataset.topicFilter);
                    if (!pillar) {
                        return;
                    }

                    const apply = () => {
                        Array.from(topic.options).forEach((option) => {
                            if (option.value === '') {
                                return;
                            }
                            const visible = pillar.value !== '' && option.dataset.pillarId === pillar.value;
                            option.hidden = !visible;
                            option.disabled = !visible;
                        });

                        const selected = topic.options[topic.selectedIndex];
                        if (selected && selected.disabled) {
                            topic.value = '';
                        }
                    };

                    pillar.addEventListener('change', apply);
                    apply();
                });
            });
        </script>
    @endpush
@endonce
