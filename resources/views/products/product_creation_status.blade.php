@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Product Creation Status</h1>

    <div id="product-status">
        @if(session('product_creation_status'))
            @foreach(session('product_creation_status') as $status)
                <p>{{ $status }}</p>
            @endforeach
        @endif
    </div>
</div>
@endsection