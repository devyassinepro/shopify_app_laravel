@extends('layouts.app')

@section('content')
<div class="pagetitle">
    <div class="row">
        <div class="col-8">
            <h1>Products</h1>
            <nav>
                <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{route('home')}}">Home</a></li>
                <li class="breadcrumb-item">Create Product</li>
                </ol>
            </nav>
        </div>
        <div class="col-4">
        @can('write-products')
        <table class="table table-borderless">
            <tbody>
            <tr>
                <td><a href="{{route('locations.sync')}}" style="float: right;" class="btn btn-success">Sync Locations</a></td>
                <td><a href="{{route('shopify.products')}}" style="float: right" class="btn btn-primary">Back</a></td>
            </tr>
            </tbody>
        </table>
        @endcan
        </div>
    </div>
</div>
<section class="section">
    <div class="row">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Create a product</h5>
                    <!-- Floating Labels Form -->
                    <form class="row g-3" method="POST" action="{{route('shopify.product.publishurl')}}">
                        @csrf
                        <div class="col-md-12">
                            <div class="form-floating">
                            <input type="text" class="form-control" id="floatingName" name="url" placeholder="Product Url" required>
                            <label for="floatingName">Product Url</label>
                            </div>
                        </div>
                        <div class="text-center">
                            <button type="submit" class="btn btn-primary" style="width:40%">Create</button>
                        </div>
                    </form><!-- End floating Labels Form -->
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
