<?php

use App\Exceptions\AccountRuleViolation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A broken account rule is a rejected request, not a server fault. It
        // is reported in the same envelope as a validation failure so that a
        // consumer has one error shape to handle, with `code` telling the two
        // apart. The mapping lives here rather than on the exception so the
        // domain layer carries no knowledge of HTTP.
        $exceptions->render(fn (AccountRuleViolation $e) => response()->json([
            'message' => $e->getMessage(),
            'code' => $e->code(),
            'errors' => [
                $e->field() => [$e->getMessage()],
            ],
        ], Response::HTTP_UNPROCESSABLE_ENTITY));

        // Route model binding failures would otherwise answer with the model
        // class and the id that was looked up. By the time render callbacks
        // run, Laravel has already wrapped the ModelNotFoundException in an
        // HTTP one, so the original is read back off the previous exception.
        $exceptions->render(function (NotFoundHttpException $e) {
            $missingModel = $e->getPrevious();

            return response()->json([
                'message' => $missingModel instanceof ModelNotFoundException
                    ? sprintf('No %s exists for the given identifier.', Str::lower(class_basename($missingModel->getModel())))
                    : 'The requested endpoint does not exist.',
            ], Response::HTTP_NOT_FOUND);
        });
    })->create();
