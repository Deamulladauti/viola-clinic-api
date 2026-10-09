<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
class StaffProfileController extends Controller {
    public function show(Request $request) {
        $user = $request->user()->loadMissing(['roles','staff.services:id,name,slug']);
        $staff = $user->staff;
        abort_if(!$staff,403,'Not a staff member');
        return response()->json([
            'user'=>['id'=>$user->id,'name'=>$user->name,'email'=>$user->email,'phone'=>$user->phone,'preferred_language'=>$user->preferred_language,'avatar_url'=>$user->avatar_url],
            'staff'=>['id'=>$staff->id,'name'=>$staff->name,'email'=>$staff->email,'phone'=>$staff->phone,'is_active'=>(bool)$staff->is_active,'services'=>$staff->services->map(fn($s)=>['id'=>$s->id,'name'=>$s->name,'slug'=>$s->slug])],
        ]);
    }
    public function updateLanguage(Request $request) {
        $user = $request->user();
        abort_if(!$user->staff,403,'Not a staff member');
        $validated = $request->validate(['preferred_language'=>['required','in:en,sq,mk']]);
        $user->preferred_language = $validated['preferred_language'];
        $user->save();
        return response()->json(['message'=>'Language updated','preferred_language'=>$user->preferred_language]);
    }
}
