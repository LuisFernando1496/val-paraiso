<?php

namespace App\Http\Controllers;

use App\Product;
use App\ProductInSale;
use App\Sale;
use App\CashClosing;
use App\Category;
use App\Box;
use App\ShoppingCart as AppShoppingCart;
use Barryvdh\DomPDF\Facade as PDF;
use App\BranchOffice;
use App\Client;
use App\Http\Resources\ProductCollection;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class SaleController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */

    public function index()
    {

        if (Auth::user()->rol_id == 1) {
            $sales = Sale::where('status', true)->with(['productsInSale.product.category', 'branchOffice', 'user'])->get();
        } else {
            $sales = Sale::where('branch_office_id', Auth::user()->branch_office_id)->where('status', true)->with(['productsInSale.product.category', 'branchOffice', 'user'])->get();
        }

        return view('sales.index', ['sales' => $sales, 'box' => CashClosing::where('user_id', '=', Auth::user()->id)->where('status', '=', false)->first()]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }
    public function paginate($items, $perPage = 10, $page = null, $options = [])
    {
        $page = $page ?: (Paginator::resolveCurrentPage() ?: 1);
        $items = $items instanceof Collection ? $items : Collection::make($items);
        return (new LengthAwarePaginator($items->forPage($page, $perPage), $items->count(), $perPage, $page, $options))->withPath(url()->current());
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $folioBranch = Sale::latest()->where('branch_office_id', Auth::user()->branchOffice->id)->pluck('folio_branch_office')->first();
        $sale = $request->all()["sale"];
        if ($sale['client_id'] != null) {
            $client = Client::findOrFail($sale['client_id']);
            if ($sale['payment_type'] == 2) {
                if ($client->authorized_credit - $sale['cart_total'] >= 0) {

                    $total_cost_sale = 0;
                    // $request['branch_office_id'] = Auth::user()->branch_office_id;
                    $sale['branch_office_id'] = Auth::user()->branch_office_id;
                    // $request['user_id'] = Auth::user()->id;
                    $sale['user_id'] = Auth::user()->id;

                    if ($folioBranch == null) {
                        $sale['folio_branch_office'] = 1;
                    } else {
                        $sale['folio_branch_office'] = $folioBranch + 1;
                    }

                    try {
                        DB::beginTransaction();
                        $client->authorized_credit = $client->authorized_credit - $sale['cart_total'];
                        $client->save();
                        $shopping_cart_id = new AppShoppingCart();
                        $shopping_cart_id->save();
                        //AGREGAR PRODUCTOS DE LA VENTA
                        $sale['shopping_cart_id'] = $shopping_cart_id->id;
                        $sale['status_credit'] = 'adeudo';
                        $sale = new Sale($sale);
                        $sale->save();
                        foreach ($request->all()["products"] as $key => $item) {
                            $product = Product::findOrFail($item['id']);
                            if ($product->category_id != 1) {
                                $product->stock = $product->stock - $item['quantity'];
                            } else {
                                //No se descuenta porque es servicio
                            }
                            $product->save();
                            $newProductInSale = [
                                'product_id' => $item['id'],
                                'sale_id' => $sale->id,
                                'quantity' => $item['quantity'],
                                'subtotal' => $item['subtotal'],
                                'sale_price' => $item['sale_price'],
                                'total' => $item['total'],
                                'total_cost' => $product->cost * $item['quantity'],
                                'discount' => $item['discount']
                            ];
                            $total_cost_sale = $total_cost_sale + $newProductInSale['total_cost'];
                            $productInSale = new ProductInSale($newProductInSale);
                            $productInSale->save();
                        }
                        $sale->cash_closing_id = CashClosing::where('user_id', '=', Auth::user()->id)->where('status', false)->pluck('id')->first();
                        $sale->total_cost = $total_cost_sale;
                        $sale->save();
                        DB::commit();
                        return response()->json(['success' => true, 'data' => Sale::where('id', $sale->id)->with(['productsInSale.product.category', 'branchOffice', 'user'])->first()]);
                    } catch (\Throwable $th) {
                        DB::rollBack();
                        return response()->json(['success' => false, 'error' => $th]);
                    }
                } else {
                    return response()->json(['ERROR' => 'Verifique la venta, saldo de credito insuficiente']);
                }
            } else {
                $total_cost_sale = 0;
                // $request['branch_office_id'] = Auth::user()->branch_office_id;
                $sale['branch_office_id'] = Auth::user()->branch_office_id;
                // $request['user_id'] = Auth::user()->id;
                $sale['user_id'] = Auth::user()->id;

                if ($folioBranch == null) {
                    $sale['folio_branch_office'] = 1;
                } else {
                    $sale['folio_branch_office'] = $folioBranch + 1;
                }

                try {
                    DB::beginTransaction();
                    $shopping_cart_id = new AppShoppingCart();
                    $shopping_cart_id->save();
                    //AGREGAR PRODUCTOS DE LA VENTA
                    $sale['shopping_cart_id'] = $shopping_cart_id->id;
                    $sale = new Sale($sale);
                    $sale->save();
                    foreach ($request->all()["products"] as $key => $item) {
                        $product = Product::findOrFail($item['id']);
                        if ($product->category_id != 1) {
                            $product->stock = $product->stock - $item['quantity'];
                        } else {
                            //No se descuenta porque es servicio
                        }
                        $product->save();
                        $newProductInSale = [
                            'product_id' => $item['id'],
                            'sale_id' => $sale->id,
                            'quantity' => $item['quantity'],
                            'subtotal' => $item['subtotal'],
                            'sale_price' => $item['sale_price'],
                            'total' => $item['total'],
                            'total_cost' => $product->cost * $item['quantity'],
                            'discount' => $item['discount']
                        ];
                        $total_cost_sale = $total_cost_sale + $newProductInSale['total_cost'];
                        $productInSale = new ProductInSale($newProductInSale);
                        $productInSale->save();
                    }
                    $sale->cash_closing_id = CashClosing::where('user_id', '=', Auth::user()->id)->where('status', false)->pluck('id')->first();
                    $sale->total_cost = $total_cost_sale;
                    $sale->save();
                    DB::commit();
                    return response()->json(['success' => true, 'data' => Sale::where('id', $sale->id)->with(['productsInSale.product.category', 'branchOffice', 'user'])->first()]);
                } catch (\Throwable $th) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'error' => $th]);
                }
            }
        } elseif ($sale['payment_type'] == 2) {
            return response()->json(['ERROR' => 'Verifique la venta, seleccione un cliente para credito']);
        } else {
            $total_cost_sale = 0;
            // $request['branch_office_id'] = Auth::user()->branch_office_id;
            $sale['branch_office_id'] = Auth::user()->branch_office_id;
            // $request['user_id'] = Auth::user()->id;
            $sale['user_id'] = Auth::user()->id;

            if ($folioBranch == null) {
                $sale['folio_branch_office'] = 1;
            } else {
                $sale['folio_branch_office'] = $folioBranch + 1;
            }

            try {
                DB::beginTransaction();
                $shopping_cart_id = new AppShoppingCart();
                $shopping_cart_id->save();
                //AGREGAR PRODUCTOS DE LA VENTA
                $sale['shopping_cart_id'] = $shopping_cart_id->id;
                $sale = new Sale($sale);
                $sale->save();
                foreach ($request->all()["products"] as $key => $item) {
                    $product = Product::findOrFail($item['id']);
                    if ($product->category_id != 1) {
                        $product->stock = $product->stock - $item['quantity'];
                    } else {
                        //No se descuenta porque es servicio
                    }
                    $product->save();
                    $newProductInSale = [
                        'product_id' => $item['id'],
                        'sale_id' => $sale->id,
                        'quantity' => $item['quantity'],
                        'subtotal' => $item['subtotal'],
                        'sale_price' => $item['sale_price'],
                        'total' => $item['total'],
                        'total_cost' => $product->cost * $item['quantity'],
                        'discount' => $item['discount']
                    ];
                    $total_cost_sale = $total_cost_sale + $newProductInSale['total_cost'];
                    $productInSale = new ProductInSale($newProductInSale);
                    $productInSale->save();
                }
                $sale->cash_closing_id = CashClosing::where('user_id', '=', Auth::user()->id)->where('status', false)->pluck('id')->first();
                $sale->total_cost = $total_cost_sale;
                $sale->save();
                DB::commit();
                return response()->json(['success' => true, 'data' => Sale::where('id', $sale->id)->with(['productsInSale.product.category', 'branchOffice', 'user'])->first()]);
            } catch (\Throwable $th) {
                DB::rollBack();
                return response()->json(['success' => false, 'error' => $th]);
            }
        }
    }

    public function search(Request $request)
    {
        $product = Product::where("products.name", "LIKE", "%{$request->search}%")->get();

        if ($product->pluck('category_id')->first() == 1) {
            $datas = Product::join('categories', 'products.category_id', 'categories.id')
                ->where("products.name", "LIKE", "%{$request->search}%")
                ->where("products.stock", ">", 0)->where("products.status", "=", true)
                ->select('products.*', 'categories.name as category_name', 'categories.id as category_id')
                ->get();
        } else {
            $datas = Product::join('brands', 'products.brand_id', 'brands.id')
                ->join('categories', 'products.category_id', 'categories.id')
                ->where("products.name", "LIKE", "%{$request->search}%")
                ->where("products.stock", ">", 0)->where("products.status", "=", true)
                ->where('products.branch_office_id', Auth::user()->branch_office_id)
                ->orWhere("brands.name", "LIKE", "%{$request->search}%")
                ->select('products.*', 'brands.name as brand_name', 'brands.id as brand_id', 'categories.name as category_name', 'categories.id as category_id')
                ->get();
        }
        return response()->json($datas);
    }

    public function searchByCode(Request $request)
    {
        $datas = Product::join('brands', 'products.brand_id', 'brands.id')
            ->join('categories', 'products.category_id', 'categories.id')
            ->where('products.branch_office_id', Auth::user()->branch_office_id)->where("products.bar_code", "=", $request->search)
            ->where("products.stock", ">", 0)->where("products.status", "=", true)
            ->select('products.*', 'brands.name as brand_name', 'brands.id as brand_id', 'categories.name as category_name', 'categories.id as category_id')
            ->get();


        return response()->json($datas);
    }

    public function productsByCategory($id)
    {

        $products = collect(
            json_decode(
                response()->json(
                    new ProductCollection(
                        Product::join('categories', 'products.category_id', 'categories.id')
                            ->where('products.category_id', $id)
                            ->where('products.status', 1)
                            ->select('products.*', 'categories.name as category_name', 'categories.id as category_id')
                            ->get()
                    )
                )->content()
            )->data
        );
        return response()->json($products);
    }
    public function reprint(Request $request)
    {
        $sale = Sale::where('id', $request->sale_id)->with(['branchOffice.address', 'productsInSale.product'])->first();
        return view('sales.ticket_new', ['sale' => $sale]);
    }
    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show(Sale $sale)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $sale = Sale::findOrFail($id);
        $productInSale = ProductInSale::where('sale_id', $sale->id)->get();

        try {
            DB::beginTransaction();
            foreach ($productInSale as $item) {
                $product = Product::findOrFail($item->product_id);
                $product->stock = $product->stock + $item->quantity;
                $product->save();
            }
            $sale->changeStatus(false);
            DB::commit();
            return back()->with(["success" => "Éxito al realizar la operación."]);
        } catch (\Throwable $th) {
            //throw $th;
            DB::rollBack();
            return back()->withErrors(["error" => "No se pudo realizar la operación."]);
        }
    }
    public function showDetails($id)
    {
        $details = ProductInSale::join('products', 'products.id', 'product_id')->where('sale_id', $id)->get();
        $sale = Sale::where('id', $id)->first();
        return view('sales.details', ['details' => $details, 'sale' => $sale]);
    }
    public function showCanceledSale()
    {
        $sale = Sale::with('productsInSale')->where('status', false)->get();


        return view('sales.refound', ['sale' => $sale]);
    }

    public function showCaja()
    {
        $branches;
        if (Auth::user()->rol_id == 1) {
            $branches = BranchOffice::all();
        } else {
            // return back()->withErrors(["error" => "No tienes permisos"]);
            $branches = [Auth::user()->branchOffice];
        }

        if (CashClosing::where('user_id', '=', Auth::user()->id)->where('status', '=', false)->count() == 0) {
            return view('sales.create', ["branches" => $branches]);
        } else {
            return view('sales.create', [
                'box' => CashClosing::where('user_id', '=', Auth::user()->id)->where('status', '=', false)->first(), "branches" => $branches, 'categories' => Category::all(),
                'clients' => Client::where('status', true)->get()
            ]);
        }
    }
}

class service
{
    public $quantity;
    public $name;

    public function __construct($quantity = '', $name = '')
    {
        $this->name = $name;
        $this->quantity = $quantity;
    }
}
