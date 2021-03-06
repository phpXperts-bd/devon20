<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Http\Requests\AttendeeRequest;
use App\Jobs\SendEmailJob;
use App\Mail\SendProfileUpdateLink;
use App\Models\Attendee;
use App\Models\Payment;
use App\Enums\AttendeeType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

//use Shipu\Aamarpay\Facades\Aamarpay;

class TicketController extends Controller
{
    protected function closeRegistration()
    {
        $registrationStart = env('EVENT_REGISTRATION_START', false);

        return !$registrationStart;
    }

    public function soldOut()
    {
        $total = Attendee::where('is_paid', 1)->count();
        if ($total >= env('PUBLIC_TICKET') || env('EVENT_TICKET_SOLD_OUT', false)) {
            return true;
        }

        return false;
    }

    /**
     * @param null $message
     * @param $toastType
     *
     * @return RedirectResponse
     */
    protected function redirectToIndex($message = null, $toastType = 'info')
    {
        if (!blank($message)) {
            toast($message, $toastType);
        }

        return redirect()->route('angularbd.index');
    }

    public function index()
    {
        if ($this->closeRegistration()) {
            return $this->redirectToIndex('Registration Coming Soon !! Please stay with us !', 'warning');
        }

        if($this->soldOut()) {
            return $this->redirectToIndex("Sold Out !!!");
        }

        $attendeeType = AttendeeType::ATTENDEE;
        return view('angularbd.buy-ticket', compact('attendeeType'));
    }

    public function showOtherRegistration(Request $request)
    {
        $route = $request->route()->getName();
        $attendeeType = AttendeeType::GUEST;

        if($route == 'register.sponsor') {
            $attendeeType = AttendeeType::SPONSOR;
        } elseif($route == 'register.volunteer') {
            $attendeeType = AttendeeType::VOLUNTEER;
        }

        return view('angularbd.buy-ticket', compact('attendeeType'));
    }

    public function storeAttendee(AttendeeRequest $request)
    {
        $attendeeType = $request->get('type');
        if ($attendeeType != AttendeeType::ATTENDEE) {
            $attendee = Attendee::create($request->all());
            if (!blank($attendee)) {
                Log::info("Attendee type " . $attendeeType . " created successfully!");
                return $this->redirectToIndex(env('SUCCESSFUL_REGISTRATION_MESSAGE'), 'success');
            } else {
                Log::info("Attendee type " . $attendeeType . " creation failed!");
                return $this->redirectToIndex("Something Went Wrong !!", 'error');
            }
        }
        if ($this->closeRegistration()) {
            return $this->redirectToIndex('Registration Closed', 'error');
        }

        if($this->soldOut()) {
            return $this->redirectToIndex("Sold Out !!!");
        }

        $attendee = Attendee::where([
            'email' => $request->get('email'),
        ])->first();

        if (blank($attendee)) {
            $attendee = Attendee::create($request->all());
        }

        //        dispatch(new SendEmailJob($attendee, new ConfirmTicket($ticket)));
        //        dispatch(new SendSmsJob($attendee, env('CONFIRM_MESSAGE')));

        if (!blank($attendee)) {
            Log::info("Attendee created successfully!");
            toast(env('EVENT_SUCCESSFUL_REGISTRATION_MESSAGE'), 'success');

            return redirect()->route('ticket.payment', $attendee->id);
        }

        return $this->redirectToIndex("Something Went Wrong !!", 'error');
    }

    public function ticketPayment(Attendee $attendee)
    {
        if ($this->soldOut()) {
            return $this->redirectToIndex("Sold Out !!!");
        }

        if ($attendee->is_paid) {
            return $this->redirectToIndex("We have received your payment already, Thank you.");
        }

        return view('angularbd.ticket-payment', compact('attendee'));
    }

    public function paymentSuccessOrFailed(Request $request)
    {
        Log::info($request->ip());
        Log::info($request->getRequestUri());
        Log::info($request->route()->getName());
        Log::debug($request->all());

        if ($request->get('pay_status') !== PaymentStatus::VALID) {
            Log::info("pay_status failure");
            return $this->redirectToIndex(env('PAYMENT_ERROR_MESSAGE'), 'error');
        }

        $attendee = Attendee::where('uuid', data_get($request, 'opt_a', null))->first();

        if (blank($attendee)) {
            Log::info("Attendee is not available! id: " . data_get($request, 'attendee_id', null));
            return $this->redirectToIndex("Attendee is not available!", 'error');
        }
        Log::info("Attendee is available, creating payments");

        $amount = env('EVENT_TICKET_PRICE');

        $payment = $this->createPayment($attendee, $request);
        if (!blank($payment)) {
            //                dispatch(new SendEmailJob($attendee, new SucccessPayment($attendee)));
            //                dispatch(new SendSmsJob($attendee, env('SUCCESS_MESSAGE')));
            if ($payment->status === PaymentStatus::VALID) {
                Log::info("Paid successfully!");
                return $this->redirectToIndex(env('PAYMENT_SUCCESS_MESSAGE'), 'success');
            } else {
                Log::info("Payments failed!");
                return $this->redirectToIndex(env('PAYMENT_ERROR_MESSAGE'), 'warning');
            }

        } else {
            Log::info("Payments failed!");
        }


        return $this->redirectToIndex(env('PAYMENT_ERROR_MESSAGE'), 'error');
    }

    public function createPayment($attendee, Request $request)
    {
        if ($request->get('pay_status') === PaymentStatus::VALID) {
            $attendee->is_paid = true;
            $attendee->save();
        }

        if (
            Payment::where('status', PaymentStatus::VALID)->where('attendee_id', data_get($request, 'attendee_id', null))->exists() ||
            Payment::where('transaction_id', data_get($request, 'transaction_id', 'done'))->exists()
        ) {
            Log::info("Already paid! id: " . $attendee->id);
            return $this->redirectToIndex("We have received your payment already!");
        }

        return Payment::create([
            'attendee_id'    => $attendee->id,
            'card_type'      => data_get($request, 'card_type', null),
            'transaction_id' => data_get($request, 'transaction_id', 'ok'),
            'amount'         => data_get($request, 'amount', 0),
            'status'         => data_get($request,'pay_status', PaymentStatus::FAILED),
            'api_response'   => $request->all()
        ]);
    }

    public function verifyAttendee($uuid)
    {
        $attendee = Attendee::where('uuid', $uuid)->first(['uuid', 'name', 'email', 'mobile', 'is_paid', 'attend_at']);

        if (!$attendee) return response()->json([
            'status' => Response::HTTP_NOT_FOUND
        ], Response::HTTP_NOT_FOUND);

        if ($attendee->attend_at) return response()->json([
            'status' => Response::HTTP_UNAUTHORIZED
        ], Response::HTTP_UNAUTHORIZED);

        return response()->json([
            'status'    => Response::HTTP_OK,
            'approve_url' => route('attendee.attend', $uuid),
            'data' => $attendee->toArray()
        ]);
    }

    public function approveAttendance($uuid)
    {
        $attendee = Attendee::where('uuid', $uuid)
            ->where(function ($query) {
                $query->where(function ($query) {
                    $query->whereIn('type', [
                        AttendeeType::VOLUNTEER,
                        AttendeeType::SPONSOR,
                        AttendeeType::GUEST
                    ]);
                });
                $query->orWhere(function ($query) {
                    $query->where('is_paid', 1)
                        ->where('type', AttendeeType::ATTENDEE);
                });
            })
            ->whereNull('attend_at')
            ->first();

        if ($attendee) {
            $attendee->attend_at = Carbon::now();
            $saved = $attendee->save();

            if ($saved) {
                return response()->json([
                    'code' => Response::HTTP_OK,
                    'message' => 'Approved successfully!'
                ]);
            }
        }

        return response()->json([
            'code' => Response::HTTP_BAD_REQUEST,
            'message' => 'Invalid Request!'
        ], Response::HTTP_BAD_REQUEST);
    }

    public function searchAttendee()
    {
        $search = request()->get('tq', '');
        $search = request()->get('q', $search);

        $attendee = Attendee::
            where(function ($query) use ($search) {
                $query->where('email', $search)
                    ->orWhereIn('mobile', [$search, '88'.$search, '+88'.$search]);
            })
            ->where(function ($query) {
                $query->where(function ($query) {
                    $query->whereIn('type', [
                        AttendeeType::VOLUNTEER,
                        AttendeeType::SPONSOR,
                        AttendeeType::GUEST
                    ]);
                });
                $query->orWhere(function ($query) {
                    $query->where('is_paid', 1)
                        ->where('type', AttendeeType::ATTENDEE);
                });
            })
            ->first(['uuid', 'name', 'type', 'email', 'mobile', 'is_paid', 'attend_at', 'misc']);

        if (!$attendee) {
            return response()->json([
                'status' => Response::HTTP_NOT_FOUND,
            ], Response::HTTP_NOT_FOUND);
        }

        if(request()->has('tq')) {
            return response()->json([
                't-shirt' => $attendee->tshirt
            ]);
        }

        if ($attendee->attend_at) {
            return response()->json([
                'status' => Response::HTTP_UNAUTHORIZED,
                'data' => $attendee->toArray()
            ], Response::HTTP_UNAUTHORIZED);
        }

        return response()->json([
            'status'    => Response::HTTP_OK,
            'approve_url' => route('attendee.attend', $attendee->uuid),
            'data' => $attendee->toArray()
        ]);
    }

    public function getAttendeeByEmail($email)
    {
        $attendee = Attendee::where('email', $email)->first();

        if (blank($attendee)) {
            return $this->redirectToIndex("Attendee is not available!", 'error');
        }
        return view('angularbd.ticket-payment', compact('attendee'));
    }

    public function showLoginForm() {
        $attendeeType = AttendeeType::GUEST;

        return view('angularbd.login', compact('attendeeType'));
    }

    public function sendProfileUpdateForm()
    {
        $attendeeType = AttendeeType::GUEST;

        return view('angularbd.update_link', compact('attendeeType'));
    }

    public function sendProfileLink(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'exists:attendees,email']
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $attendee = Attendee::where('email', $request->input('email'))->first();

        try {
            dispatch(new SendEmailJob($attendee, new SendProfileUpdateLink($attendee)));
//            Mail::to($attendee->email)->send(new SendProfileUpdateLink($attendee));
            toast('Successfully sent! Check your mail.');
        } catch (\Exception $exception) {
            toast('Something went wrong! Please try again', 'warning');
        }

        return back();
    }

    public function attendeeSignIn(Request $request) {
        $validator = Validator::make($request->all(), [
            'hash_code' => ['required', 'string', 'min:20']
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $attendee = Attendee::where('hash_code', $request->input('hash_code'))->first();

        if (blank($attendee)) {
            toast('Invalid hashcode!', 'warning');
            return back();
        }

        Auth::loginUsingId($attendee->id);

        return redirect()->route('attendee.update.form.show');
    }

    public function showAttendeeForm($code) {

        $attendeeType = AttendeeType::GUEST;

        $attendee = Attendee::where('hash_code', $code)->first();

        return view('angularbd.buy-ticket-edit', compact('attendeeType', 'attendee'));
    }

    public function updateAttendee($code, Request $request) {

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string'],
            'profession' => ['required', 'string'],
            'social_profile_url' => ['required', 'url'],
            'address_line_1' => ['nullable', 'string'],
            'address_line_2' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'district' => ['nullable', 'string']
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $attendee = Attendee::where('hash_code', $code)->first();

        if (blank($attendee)) {
            abort(404);
        }

        foreach ($validator->validated() as $column => $value) {
            $attendee->{$column} = $value;
        }

        try {
            $attendee->save();
            toast('Update successfully!', 'success');
        } catch (\Exception $ex) {
            toast('Not updated!', 'warning');
        }

        return back();
    }
}
