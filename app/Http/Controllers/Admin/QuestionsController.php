<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Question\BulkDestroyQuestion;
use App\Http\Requests\Admin\Question\DestroyQuestion;
use App\Http\Requests\Admin\Question\IndexQuestion;
use App\Http\Requests\Admin\Question\StoreQuestion;
use App\Http\Requests\Admin\Question\UpdateQuestion;
use App\Models\Question;
use App\Models\Unit;
use Brackets\AdminListing\Facades\AdminListing;
use Carbon\Carbon;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class QuestionsController extends Controller
{

    /**
     * Display a listing of the resource.
     *
     * @param IndexQuestion $request
     * @return array|Factory|View
     */
    public function index(IndexQuestion $request)
    {
        // create and AdminListing instance for a specific model and
        $data = AdminListing::create(Question::class)->processRequestAndGet(
            // pass the request with params
            $request,

            // set columns to query
            ['id', 'description', 'answer',  'order', 'type', 'link', 'unit_id', 'test_id'],

            // set columns to searchIn
            ['id', 'description', 'answer', 'type', 'link'],
            function ($query) use ($request) {


                if ($request->has('units')) {
                    $query->with(['Unit']);

                    // add this line if you want to search by category attributes
                    $query->join('units', 'units.id', '=', 'questions.unit_id');
                    $query->whereIn('unit_id', $request->get('units'));
                }

                if ($request->has('test')) {
                    $query->with(['Test']);

                    // add this line if you want to search by category attributes
                    $query->join('tests', 'tests.id', '=', 'questions.test_id');
                    $query->whereIn('test_id', $request->get('test'));
                }
            },
        );

        if ($request->ajax()) {
            if ($request->has('bulk')) {
                return [
                    'bulkItems' => $data->pluck('id')
                ];
            }
            return ['data' => $data];
        }

        return view('admin.question.index', ['data' => $data]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @throws AuthorizationException
     * @return Factory|View
     */
    public function create(IndexQuestion $request)
    {
      
        $this->authorize('admin.question.create');
        $data = [];
        if ($request->has('units')) {
            $unit = Unit::where('id', $request->units[0])->first();
            $data = ['unit' => $unit, 'type' => 'MCQS', 'marks' => 1, 'paid' => 1];
        }
        if ($request->has('test')) {
            $data = ['unit' => ['id' => 0], 'type' => 'MCQS', 'marks' => 1, 'paid' => 1, 'test_id' => $request->test[0], 'belong_to' => 'test'];
        }

        return view('admin.question.create', [
            'units' => Unit::where('enabled', 1)->get() ?? [],
            'data' => $data,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreQuestion $request
     * @return array|RedirectResponse|Redirector
     */
    public function store(StoreQuestion $request)
    {


        $redirect_to = '';
        // Sanitize input
        $sanitized = $request->validated();
        $sanitized['unit_id'] = $request->getUnitId();
        if ($sanitized['unit_id'] == 0) {
            $sanitized['unit_id'] = 0;
            $redirect_to = 'admin/questions?test[]=' .  $sanitized['test_id'];
        } else {

            $sanitized['test_id'] = 0;
            $redirect_to = 'admin/questions?units[]=' .  $sanitized['unit_id'];
        }
        // Store the Question
        $question = Question::create($sanitized);

        if ($request->ajax()) {
            return ['redirect' => url($redirect_to), 'message' => trans('brackets/admin-ui::admin.operation.succeeded')];
        }

        return redirect($redirect_to);
    }

    /**
     * Display the specified resource.
     *
     * @param Question $question
     * @throws AuthorizationException
     * @return void
     */
    public function show(Question $question)
    {
        $this->authorize('admin.question.show', $question);

        // TODO your code goes here
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param Question $question
     * @throws AuthorizationException
     * @return Factory|View
     */
    public function edit(Question $question)
    {
        $this->authorize('admin.question.edit', $question);
        $question->load('Unit');


        return view('admin.question.edit', [
            'question' => $question,
            'units' => Unit::where('enabled', 1)->get(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdateQuestion $request
     * @param Question $question
     * @return array|RedirectResponse|Redirector
     */
    public function update(UpdateQuestion $request, Question $question)
    {
        // Sanitize input
        $sanitized = $request->getSanitized();

        // Update changed values Question
        $question->update($sanitized);


        $redirect_to = '';
        // Sanitize input
        if ($question->unit_id == 0) {
            $redirect_to = 'admin/questions?test[]=' .  $question['test_id'];
        } else {
            $redirect_to = 'admin/questions?units[]=' .  $question['unit_id'];
        }


        if ($request->ajax()) {
            return [
                'redirect' => url($redirect_to),
                'message' => trans('brackets/admin-ui::admin.operation.succeeded'),
            ];
        }

        return redirect($redirect_to);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param DestroyQuestion $request
     * @param Question $question
     * @throws Exception
     * @return ResponseFactory|RedirectResponse|Response
     */
    public function destroy(DestroyQuestion $request, Question $question)
    {
        $question->delete();

        if ($request->ajax()) {
            return response(['message' => trans('brackets/admin-ui::admin.operation.succeeded')]);
        }

        return redirect()->back();
    }

    /**
     * Remove the specified resources from storage.
     *
     * @param BulkDestroyQuestion $request
     * @throws Exception
     * @return Response|bool
     */
    public function bulkDestroy(BulkDestroyQuestion $request): Response
    {
        DB::transaction(static function () use ($request) {
            collect($request->data['ids'])
                ->chunk(1000)
                ->each(static function ($bulkChunk) {
                    DB::table('questions')->whereIn('id', $bulkChunk)
                        ->update([
                            'deleted_at' => Carbon::now()->format('Y-m-d H:i:s')
                        ]);

                    // TODO your code goes here
                });
        });

        return response(['message' => trans('brackets/admin-ui::admin.operation.succeeded')]);
    }
}
