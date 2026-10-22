{{--
    Catalog selections shared by the content and idea forms. Expected data:
    $record (the edited ContentItem, or null on create), $catalogOptions and
    $part: 'pillar_topic' (pillar and topic columns) or 'multi' (objectives,
    formats and channels columns).

    Old input arrives as strings while stored ids are integers, so every
    comparison is made on strings; otherwise a failed save would re-render the
    multi-selects empty. A retired option is only present when the edited row
    already stores it, and is labelled "(Retirada)" so it stays selected.

    A standard field the project hides ($project, S2) is not rendered at all, so
    nothing is posted for it and the stored value is kept.
--}}
@php
    $retired = fn ($option): string => $option->is_active ? '' : ' (Retirada)';
    $hiddenFields = $project->hiddenStandardFields();
@endphp

@if ($part === 'pillar_topic')
    @php $selectedTopic = (string) old('content_topic_id', $record?->content_topic_id); @endphp
    @unless (in_array('pillar', $hiddenFields, true))
    <div class="col-md-4">
        <x-select name="content_pillar_id" label="Pilar de contenido" placeholder="Sin pilar"
                  :options="$catalogOptions['pillars']->mapWithKeys(fn ($pillar) => [$pillar->id => $pillar->name.$retired($pillar)])->all()"
                  :value="$record?->content_pillar_id"/>
    </div>
    @endunless
    @unless (in_array('topic', $hiddenFields, true))
    <div class="col-md-4">
        <x-label for="content_topic_id" label="Tema"/>
        <select name="content_topic_id" id="content_topic_id" data-topic-filter="content_pillar_id"
                class="form-select @error('content_topic_id') is-invalid @enderror">
            <option value="" @selected($selectedTopic === '')>Sin tema</option>
            @foreach ($catalogOptions['topics'] as $topic)
                <option value="{{ $topic->id }}" data-pillar-id="{{ $topic->content_pillar_id }}" @selected($selectedTopic === (string) $topic->id)>{{ $topic->name }}{{ $retired($topic) }}</option>
            @endforeach
        </select>
        <div class="form-text">El tema debe pertenecer al pilar elegido.</div>
        <x-validation-error name="content_topic_id"/>
        @include('content.items._topic_filter')
    </div>
    @endunless
@else
    @foreach (['objectives' => 'Objetivos', 'formats' => 'Formato', 'channels' => 'Red social o canal'] as $group => $groupLabel)
        @continue(in_array($group, $hiddenFields, true))
        @php
            $selected = array_map('strval', (array) old($group, $record?->{$group}->pluck('id')->all() ?? []));
        @endphp
        <div class="col-md-6">
            <x-label :for="$group" :label="$groupLabel"/>
            <select name="{{ $group }}[]" id="{{ $group }}" multiple size="5"
                    class="form-select @error($group) is-invalid @enderror">
                @foreach ($catalogOptions[$group] as $option)
                    <option value="{{ $option->id }}" @selected(in_array((string) $option->id, $selected, true))>{{ $option->name }}{{ $retired($option) }}</option>
                @endforeach
            </select>
            <x-validation-error :name="$group"/>
        </div>
    @endforeach
@endif
