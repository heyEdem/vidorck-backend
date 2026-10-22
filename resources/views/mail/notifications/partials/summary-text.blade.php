Order {!! $v['orderNumber'] !!}

@foreach ($v['items'] as $item)
{{ $item['child'] ? '    ' : '' }}- {!! $item['name'] !!}@if ($item['size'] !== '') ({!! $item['size'] !!})@endif x{!! $item['qty'] !!}

@endforeach
Total paid: {!! $v['total'] !!}
Payment method: {!! $v['paymentMethod'] !!}
Delivery address: {!! implode(', ', $v['delivery']) !!}
