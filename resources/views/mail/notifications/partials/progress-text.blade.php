@php($steps = ['Paid', 'Packed', 'On its way', 'Delivered'])
@php($at = array_search($step, $steps, true))
Progress: {!! implode(' > ', array_map(fn ($label, $i) => $i < $at ? $label.' ✓' : ($i === $at ? '['.$label.']' : $label), $steps, array_keys($steps))) !!}
