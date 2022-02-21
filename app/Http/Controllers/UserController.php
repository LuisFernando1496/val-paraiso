<?php

namespace App\Http\Controllers;

use App\BranchOffice;
use App\Rol;
use App\Address;
use App\Client;
use Illuminate\Http\Request;
use App\User;
use DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $user = Auth::user();
        if ($user->rol_id == 1) {
            return view('user.index', ['users' => User::with('address')->where('status', 1)->where('id', '!=', Auth::user()->id)->get(), 'offices' => BranchOffice::where('status', true)->get(), 'rols' => Rol::all()]);
        } else {
            return back()->withErrors(["error" => "No tienes permisos"]);
        }
    }

    public function indexClient()
    {
        $user = Auth::user();
        if ($user->rol_id == 1) {
            return view('user.indexClient', ['users' => Client::where('status', 1)->get()]);
        } else {
            return back()->withErrors(["error" => "No tienes permisos"]);
        }
    }
    public function paginate($items, $perPage = 10, $page = null, $options = [])
    {
        $page = $page ?: (Paginator::resolveCurrentPage() ?: 1);
        $items = $items instanceof Collection ? $items : Collection::make($items);
        return (new LengthAwarePaginator($items->forPage($page, $perPage), $items->count(), $perPage, $page, $options))->withPath(url()->current());
    }
    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $user = new User();
        $address = new Address();
        $current_user = Auth::user();
        if ($current_user->rol_id == 1) {
            $rols = Rol::all();
            $branchOffices = BranchOffice::all();
            return view('', ['user' => $user, 'rols' => $rols, 'branchOffices' => $branchOffices, 'address' => $address]);
        } elseif ($current_user->rol_id == 2) {
            return back()->withErrors(["error" => "No tienes permisos"]);
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            if ($request->has('branch_office_id')) {
                $request['address_id'] = Address::create($request->only(['street', 'suburb', 'postal_code', 'city', 'state', 'country', 'ext_number', 'int_number']))->id;
                // $request['user_id'] = 1;
                $request['user_id'] = auth()->user()->id;
                // $request['branch_office_id'] = auth()->user()->branchOffice->id;
                User::create($request->only(['address_id', 'branch_office_id', 'rol_id', 'phone', 'password', 'email', 'curp', 'rfc', 'last_name', 'name', 'user_id']));
            } else {
                $request['user_id'] = auth()->user()->id;
                $client = new Client($request->all());
                $client->save();
            }
            DB::commit();
            return back()->with(["success" => "Éxito al realizar la operación."]);
        } catch (\Throwable $th) {
            DB::rollback();
            return back()->withErrors(["error" => "No se pudo realizar la operación."]);
            //return $th->getMessage();
            //throw $th;
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, User $user)
    {
        DB::beginTransaction();
        try {
            $this->validate($request, [
                'street' => 'required',
                'suburb' => 'required',
                'postal_code' => 'required',
                'city' => 'required',
                'state' => 'required',
                'country' => 'required',
                'name' => 'required',
                'last_name' => 'required',
                'rfc' => 'required',
                'curp' => 'required',
                'email' => 'required',
                'phone' => 'required',
                'ext_number' => 'required',
                'int_number' => 'required',
            ]);
            $user->address->edit($request->only(['street', 'suburb', 'postal_code', 'city', 'state', 'country']));
            $user->edit($request->only(['address_id', 'branch_office_id', 'rol_id', 'phone', 'email', 'curp', 'rfc', 'last_name', 'name']));
            DB::commit();
            return back()->with(["success" => "Éxito al realizar la operación."]);
        } catch (\Throwable $th) {
            DB::rollback();
            return back()->withErrors(["error" => "No se pudo realizar la operación."]);
            //return $th->getMessage();
            //throw $th;
        }
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
    public function update(Request $request, User $user)
    {
        if (Auth::user()->rol_id == 1) {
            DB::beginTransaction();
            try {
                if ($request->has('branch_office_id')) {
                    $user->address->edit($request->only(['street', 'suburb', 'postal_code', 'city', 'state', 'country']));
                    $user->edit($request->only(['address_id', 'branch_office_id', 'rol_id', 'phone', 'email', 'curp', 'rfc', 'last_name', 'name']));
                } else {
                    $client = Client::findOrFail($request['client_id']);
                    $client->update($request->all());
                }
                DB::commit();
                return back()->with(["success" => "Éxito al realizar la operación."]);
            } catch (\Throwable $th) {
                return $th;
                DB::rollback();
                return back()->withErrors(["error" => "No se pudo realizar la operación."]);
                //return $th->getMessage();
                //throw $th;
            }
        } else {
            return back()->withErrors(["error" => "No tienes permisos"]);
        }
    }

    public function checkAdmin(Request $request)
    {
        $user = User::where('email', $request->email)->first();
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['success' => false, 'message' => 'The provided credentials are incorrect'], 404);
        }
        if ($user->rol_id == 1 || $user->rol_id == 2) {
            return response()->json(['success' => true, 'user' => $user], 200);
        } else {
            return response()->json(['success' => false, 'message' => 'You are not admin or manager'], 401);
        }
    }
    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request,User $user)
    {
        if (Auth::user()->rol_id == 1) {
            if ($request->has('eliminate_client')) {
                $client = Client::findOrFail($request['eliminate_client']);
                $client->changeStatus(false);
            } else {
                $user->changeStatus(false);
            }            
            return back()->with(["success" => "Éxito al realizar la operación."]);
        } else {
            return back()->withErrors(["error" => "No tienes permisos"]);
        }
    }

    public function changePassword(Request $request, $id)
    {
        $user = User::where('id', $id)->first();

        DB::beginTransaction();
        try {
            $request['password'] = $request->newPass;
            $user->update($request->only(['password']));
            DB::commit();
            return back()->with(["success" => "Éxito al realizar la operación."]);
        } catch (\Throwable $th) {
            DB::rollback();
            return back()->withErrors(["error" => "No se pudo realizar la operación."]);
            //return $th->getMessage();
            //throw $th;
        }
        //
    }
}
