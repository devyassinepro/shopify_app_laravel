<?php

namespace App\Http\Controllers;

use App\Jobs\Shopify\Sync\Product;
use App\Traits\FunctionTrait;
use App\Traits\RequestTrait;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ProductsController extends Controller {
    use RequestTrait, FunctionTrait;

    public function __construct() {
        $this->middleware('auth');        
    }

    public function create() {
        $user = Auth::user();
        $store = $user->getShopifyStore;
        $locations = $this->getLocationsForStore($store);
        return view('products.create', ['locations' => $locations]);
    }

    public function importproduct() {
        $user = Auth::user();
        $store = $user->getShopifyStore;
        $locations = $this->getLocationsForStore($store);
        return view('products.importproduct', ['locations' => $locations]);
    }

    public function importstore() {
        $user = Auth::user();
        $store = $user->getShopifyStore;
        $locations = $this->getLocationsForStore($store);
        return view('products.importstore', ['locations' => $locations]);
    }


    private function getLocationsForStore($store) {
        $locations = $store->getLocations()
                           ->where(function ($query) use ($store) {
                                return $store->hasRegisteredForFulfillmentService() ? $query->where('name', config('custom.fulfillment_service_name')) : true;
                           })
                           ->select(['id', 'name', 'admin_graphql_api_id', 'legacy']);
        //If not then you can select Shopify's default locations
        return $locations->get(); 
    }

     // 
    //
    //Import Product Single
    //Import Product Single
    //


    public function publishProductUrl(Request $request) {
        $request = $request->all();
        $user = Auth::user();
        $store = $user->getShopifyStore;
        $locations = $this->getLocationsForStore($store);
        
        try {
            $productCreateMutation = 'productCreate (input: {' . $this->getGraphQLPayloadForProductPublishUrl($store, $request) . '}) { 
                product { id }
                userErrors { field message }
            }';
            Log::info("Json file " . $productCreateMutation);
            $mutation = 'mutation { ' . $productCreateMutation . ' }';
            
            $endpoint = getShopifyURLForStore('graphql.json', $store);
            $headers = getShopifyHeadersForStore($store);
            $payload = ['query' => $mutation];
            
            $response = $this->makeAnAPICallToShopify('POST', $endpoint, null, $headers, $payload);
            Log::info('Shopify API Response:', ['response' => $response]);
            
            // Check the response
            if (isset($response['statusCode']) && $response['statusCode'] == 200) {
                if (isset($response['body']['data']['productCreate']['userErrors']) && !empty($response['body']['data']['productCreate']['userErrors'])) {
                    $errors = $response['body']['data']['productCreate']['userErrors'];
                    $errorMessages = array_map(function($error) {
                        return $error['message'];
                    }, $errors);
                    return back()->with('error', 'Product creation failed: ' . implode(', ', $errorMessages));
                }
                Product::dispatch($user, $store);
                return back()->with('success', 'Product Created!');
            } else {
                return back()->with('error', 'Product creation failed!');
            }
        } catch (Exception $e) {
            Log::error('Error in publishProductUrl:', ['message' => $e->getMessage()]);
            return back()->with('error', 'Product creation failed: ' . $e->getMessage());
        }
    }

    
    private function getGraphQLPayloadForProductPublishUrl($store, $request) {
        $url = $request['url'];
        $opts = array('http' => array('header' => "User-Agent:MyAgent/1.0\r\n"));
        $context = stream_context_create($opts);
        $html = file_get_contents($url . '.json', false, $context);
        $productData = json_decode($html, true);
    
        $temp = [];
        $temp[] = 
            ' title: "' . $productData['product']['title'] . '",
              published: true,
              vendor: "' . $productData['product']['vendor'] . '" ';
        if (isset($productData['product']['body_html']) && $productData['product']['body_html'] !== null)
             $escapedDescriptionHtml = json_encode($productData['product']['body_html']);

            $temp[] = ' descriptionHtml: ' . $escapedDescriptionHtml . '';

        if (isset($productData['product']['product_type']))
            $temp[] = ' productType: "' . $productData['product']['product_type'] . '"';
            $temp[] = ' tags: ["' . implode('", "', explode(',', $productData['product']['tags'])) . '"]';

            if (isset($productData['product']['options']) && is_array($productData['product']['options'])) {
                // Extract all option values and combine them into a single string
                $optionValues = array_reduce($productData['product']['options'], function($carry, $option) {
                    // Combine the values of each option into a single string, separated by commas
                    return $carry . implode(',', $option['values']) . ',';
                }, '');
            
                // Remove the trailing comma
                $optionValues = rtrim($optionValues, ',');
            
                // Wrap the combined option values in quotes and prepare the final options array format
                $formattedOptions = '"' . $optionValues . '"';
            
                $temp[] = 'options: [' . $formattedOptions . ']';
            } else {
                $formattedOptions = '';
            }

        if (isset($productData['product']['variants']) && is_array($productData['product']['variants'])) {
            $temp[] = 'variants: [' . $this->getVariantsGraphQLConfigUrl($productData) . ']';

        }

        if (isset($productData['product']['images']) && is_array($productData['product']['images'])) {
            $temp[] = 'images: [' . $this->getImagesGraphQLConfigUrl($productData) . ']';

        }
    
        return implode(',', $temp);
    }
    

    private function getVariantsGraphQLConfigUrl($productData) {
        try {
            $str = [];
            foreach ($productData['product']['variants'] as $key => $variant) {

                $compareAtPrice = !empty($variant['compare_at_price']) ? $variant['compare_at_price'] : 'null';
                $compareAtPriceField = $compareAtPrice !== 'null' ? 'compareAtPrice: ' . $compareAtPrice . ',' : '';

                  // Ensure option values are correctly set
                $optionValues = [];
                if (isset($variant['option1']) && $variant['option1'] !== null) {
                    $optionValues[] = $variant['option1'];
                }
                if (isset($variant['option2']) && $variant['option2'] !== null) {
                    $optionValues[] = $variant['option2'];
                }
                if (isset($variant['option3']) && $variant['option3'] !== null) {
                    $optionValues[] = $variant['option3'];
                }
                $formattedOptionValues = implode('", "', $optionValues);
            

                $str[] = '{
                    taxable: false,
                    title: "'.$variant['title'].'",
                    ' . $compareAtPriceField . '
                    sku: "'.$variant['sku'].'",
                    options: [" '.$formattedOptionValues.' "],
                    position: '.$variant['position'].',
                    imageSrc: "'.$variant['image_id'].'",
                    inventoryItem: {cost: '.$variant['price'].', tracked: false},
                    inventoryManagement: null,
                    inventoryPolicy: DENY,
                    price: '.$variant['price'].'
                }';
            }
            return implode(',', $str); 
        } catch (Exception $e) {
            dd($e->getMessage().' '.$e->getLine());
            return null;
        }
    }

    private function getImagesGraphQLConfigUrl($productData) {
        try {
            $str = [];
            foreach ($productData['product']['images'] as $key => $image) {
                $str[] = '{
                    src: "'.$image['src'].'",
                }';
            }
            return implode(',', $str); 
        } catch (Exception $e) {
            dd($e->getMessage().' '.$e->getLine());
            return null;
        }
    }
    
    // 
    //
    //Import Store Products
    //Import Store Products
    //
    public function publishStoreUrl(Request $request) {
        $request = $request->all();
        $user = Auth::user();        
        $store = $user->getShopifyStore;
        $locations = $this->getLocationsForStore($store);

        $url = $request['url'];
        $opts = array('http' => array('header' => "User-Agent:MyAgent/1.0\r\n"));
        $context = stream_context_create($opts);
        $html = file_get_contents($url . 'products.json?page=1&limit=250', false, $context);
        $products = json_decode($html)->products;

        foreach ($products as $product) {
            try {

                $productCreateMutation = 'productCreate (input: {' . $this->getGraphQLPayloadForStorePublishUrl($product) . '}) { 
                    product { id }
                    userErrors { field message }
                }';
    
                Log::info("Json file " . $productCreateMutation);

                $mutation = 'mutation { ' . $productCreateMutation . ' }';
    
                $endpoint = getShopifyURLForStore('graphql.json', $store);
                $headers = getShopifyHeadersForStore($store);

                $payload = ['query' => $mutation];
    
                $response = $this->makeAnAPICallToShopify('POST', $endpoint, null, $headers, $payload);
                Log::info('Shopify API Response:', ['response' => $response]);
    
                    // Check the response
                if (isset($response['statusCode']) && $response['statusCode'] == 200) {
                    if (isset($response['body']['data']['productCreate']['userErrors']) && !empty($response['body']['data']['productCreate']['userErrors'])) {
                        $errors = $response['body']['data']['productCreate']['userErrors'];
                        $errorMessages = array_map(function($error) {
                            return $error['message'];
                        }, $errors);
                        return back()->with('error', 'Product creation failed: ' . implode(', ', $errorMessages));
                    }
                    Product::dispatch($user, $store);
                } else {
                    return back()->with('error', 'Product creation failed!');
                }
            } catch (Exception $e) {
                Log::error('Error in publishProductUrl:', ['message' => $e->getMessage()]);
                return back()->with('error', 'Product creation failed: ' . $e->getMessage());
            }
        }

        return back()->with('success', 'Products created successfully!');
    }
    
    private function getGraphQLPayloadForStorePublishUrl($product) {
        $temp = [];
        $temp[] = 'title: "' . addslashes($product->title) . '"';
        $temp[] = 'published: true';
        $temp[] = 'vendor: "' . addslashes($product->vendor) . '"';
    
        if (isset($product->body_html)) {
            $escapedDescriptionHtml = json_encode($product->body_html);
            $temp[] = 'descriptionHtml: ' . $escapedDescriptionHtml;
        }
    
        if (isset($product->product_type)) {
            $temp[] = 'productType: "' . addslashes($product->product_type) . '"';
        }
    
        // if (isset($product->tags)) {
        //     $tags = array_map('addslashes', explode(',', $product->tags));
        //     $temp[] = 'tags: ["' . implode('", "', $tags) . '"]';
        // }
    
        if (isset($product->options) && is_array($product->options)) {
            $options = [];
            foreach ($product->options as $option) {
                $optionValues = implode(',', array_map('addslashes', $option->values));
                $options[] = '"' . $optionValues . '"';
            }
            $temp[] = 'options: [' . implode(', ', $options) . ']';

        }
    
        if (isset($product->variants) && is_array($product->variants)) {
            $temp[] = 'variants: [' . $this->getVariantsGraphQLConfigUrlStore($product->variants) . ']';
        }
    
        if (isset($product->images) && is_array($product->images)) {
            $temp[] = 'images: [' . $this->getImagesGraphQLConfigUrlStore($product->images) . ']';
        }
    
        return implode(', ', $temp);
    }
    
    private function getVariantsGraphQLConfigUrlStore($variants) {
        $str = [];
        foreach ($variants as $variant) {
            $compareAtPriceField = !empty($variant->compare_at_price) ? 'compareAtPrice: "' . $variant->compare_at_price . '",' : '';
    
            // Ensure option values are correctly set
            $optionValues = [];
            if (isset($variant->option1)) $optionValues[] = addslashes($variant->option1);
            if (isset($variant->option2)) $optionValues[] = addslashes($variant->option2);
            if (isset($variant->option3)) $optionValues[] = addslashes($variant->option3);
            $formattedOptionValues = implode('", "', $optionValues);
    
            $str[] = '{
                taxable: false,
                title: "' . addslashes($variant->title) . '",
                ' . $compareAtPriceField . '
                sku: "' . addslashes($variant->sku) . '",
                options: ["' . $formattedOptionValues . '"],
                position: ' . $variant->position . ',
                inventoryItem: {cost: ' . $variant->price . ', tracked: false},
                inventoryManagement: null,
                inventoryPolicy: DENY,
                price: ' . $variant->price . '
            }';
        }
        return implode(', ', $str);
    }
    
    private function getImagesGraphQLConfigUrlStore($images) {
        $str = [];
        foreach ($images as $image) {
            $str[] = '{
                src: "' . addslashes($image->src) . '"
            }';
        }
        return implode(', ', $str);
    }
    // public function getInventoryQuantitiesStringUrl($key, $request, $locations) {
    //     $str = '[';
    //     $temp_payload = [];
    //     foreach ($locations as $location) {
    //         if (isset($request[$location['id'].'_inventory_'.($key + 1)])) {
    //             $temp_payload[] = '{ availableQuantity: '.$request[$location['id'].'_inventory_'.($key + 1)].', locationId: "'.$location['admin_graphql_api_id'].'" }';
    //         }
    //     }
    //     $str .= implode(',', $temp_payload);
    //     $str .= ']';
    //     return $str;
    // }


    public function publishProduct(Request $request) {
        $request = $request->all();
        $user = Auth::user();
        $store = $user->getShopifyStore;
        $locations = $this->getLocationsForStore($store);
        $productCreateMutation = 'productCreate (input: {'.$this->getGraphQLPayloadForProductPublish($store, $request, $locations).'}) { 
            product { id }
            userErrors { field message }
        }';
        Log::info("Json file ".$productCreateMutation);
        $mutation = 'mutation { '.$productCreateMutation.' }';
        Log::info('GraphQL Mutation:', ['mutation' => $mutation]);
        $endpoint = getShopifyURLForStore('graphql.json', $store);
        $headers = getShopifyHeadersForStore($store);
        $payload = ['query' => $mutation];
        $response = $this->makeAnAPICallToShopify('POST', $endpoint, null, $headers, $payload);
        Log::info('Shopify API Response:', ['response' => $response]);
        Product::dispatch($user, $store);
        return back()->with('success', 'Product Created!');
    }

    //Import By inputs
    private function getGraphQLPayloadForProductPublish($store, $request, $locations) {
    
        $temp = [];
        $temp[] = 
          ' title: "'.$request['title'].'",
            published: true,
            vendor: "'.$request['vendor'].'" ';
        if(isset($request['desc']) && $request['desc'] !== null)
            $temp[] = ' descriptionHtml: "'.$request['desc'].'"';
        if(isset($request['product_type'])) 
            $temp[] = ' productType: "'.$request['product_type'].'"';
        if(isset($request['tags'])) 
            $temp[] = ' tags: ['.$this->returnTags($request['tags']).']';
        if(isset($request['variant_title']) && is_array($request['variant_title'])) {
            $temp[] = ' options: ["'.implode(', ',$request['variant_title']).'"]';
            $temp[] = ' variants: ['.$this->getVariantsGraphQLConfig($store, $request, $locations).']';
        }  

        return implode(',', $temp);
    }


    public function getHTMLForAddingVariant(Request $request) {
        $user = Auth::user();
        $store = $user->getShopifyStore;
        $locations = $this->getLocationsForStore($store);
        return response()->json([
            'status' => true, 
            'html' => view('products.partials.add_variant', [
                'count' => $request->count,
                'locations' => $locations
            ])->render()
        ]);
    }


    private function getVariantsGraphQLConfig($store, $request, $locations) {
        try {
            if(is_array($request['variant_title'])) {
                $str = [];
                foreach($request['variant_title'] as $key => $variant_title){
                    $str[] = '{
                        taxable: false,
                        title: "'.$variant_title.'",
                        compareAtPrice: '.$request['variant_caprice'][$key].',
                        sku: "'.$request['sku'][$key].'",
                        options: [ "'.$variant_title.'" ],
                        inventoryItem: {cost: '.$request['variant_price'][$key].', tracked: true},
                        inventoryQuantities: '.$this->getInventoryQuantitiesString($key, $request, $locations).',
                        inventoryManagement: null,
                        inventoryPolicy: DENY,                
                        price: '.$request['variant_price'][$key].' }';
                    }
                }
                return implode(',', $str); 
        } catch(Exception $e) {
            dd($e->getMessage().' '.$e->getLine());
            return null;
        }
    }

    private function returnTags($tags) {
        try {
            $tags = explode(',', $tags);
            $return_val = [];
            foreach($tags as $tag)
                $return_val[] = '"'.$tag.'"';
            return implode(',', $return_val);
        } catch(Exception $e) {
            return null;
        }
    }
    //Had to do $key + 1 because PHP starts its arrays with 0 and i was having counter starting from 1 in the frontend
    public function getInventoryQuantitiesString($key, $request, $locations) {
        $str = '[';
        $temp_payload = [];
        foreach($locations as $location){
            if(isset($request[$location['id'].'_inventory_'.($key+1)]))
                $temp_payload[] = '{ availableQuantity: '.$request[$location['id'].'_inventory_'.($key+1)].', locationId: "'.$location['admin_graphql_api_id'].'" }';
        }
        $str .= implode(',', $temp_payload);
        $str .= ']';
        return $str;
    }

    public function changeProductAddToCartStatus(Request $request) {
        try {
            if($request->has('product_id')) {
                $user = Auth::user();
                $store = $user->getShopifyStore;
                $targetTag = config('custom.add_to_cart_tag_product');
                $product = $store->getProducts()->where('table_id', $request->product_id)->first();
                $data = $product->getAddToCartStatus();
                if($data['status'] === true) {
                    //The tag is already present.
                    //Just remove it from the tags and update the product.
                    $tags = $product->tags;
                    if($tags !== null && strlen($tags) > 0) {
                        $tags = explode(',', $tags);
                        if(in_array($targetTag, $tags)) {
                            foreach($tags as $key => $tag) {
                                if($tag === $targetTag) {
                                    unset($tags[$key]);
                                }
                            }
                        }
                        $tags = implode(',', $tags);
                    } else {
                        $tags = '';
                    }
                } else {
                    //Remove Add to Cart functionality here.
                    //Basically meaning add the tag 'buy-now'
                    $tags = $product->tags;
                    if($tags !== null && strlen($tags) > 0) {
                        $tags = explode(',', $tags);
                        if(!in_array($targetTag, $tags)) {
                            $tags[] = $targetTag;
                        }

                        $tags = implode(',', $tags); //Make it a string of tags
                    } else {
                        //No tags present
                        $tags = $targetTag;
                    }

                    $endpoint = getShopifyURLForStore('products/'.$product->id.'.json', $store);
                    $headers = getShopifyHeadersForStore($store);
                    $payload = [
                        'product' => [
                            'id' => $product->id,
                            'tags' => $tags
                        ]
                    ];

                    $response = $this->makeAnAPICallToShopify('PUT', $endpoint, null, $headers, $payload);
                    if(isset($response['statusCode']) && $response['statusCode'] === 200) {
                        Product::dispatch($user, $store)->onConnection('sync');
                        return back()->with('success', 'Status changed successfully!');
                    } else {
                        Log::info('Response from Shopify API call');
                        Log::info($response);
                        return back()->with('error', 'Not successful!');
                    }
                }
            } else {
                return back()->with('error', 'Please select a product');
            }
        } catch(Exception $e) {
            return back()->with('error', $e->getMessage().' '.$e->getLine());
        }
    }
}
