<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            // 可以在这里添加日志记录逻辑
        });
    }

    /**
     * Render an exception into an HTTP response.
     */
    public function render($request, Throwable $e)
    {
        // API 请求返回 JSON
        if ($request->expectsJson() || $request->is('api/*')) {
            return $this->renderApiException($request, $e);
        }

        return parent::render($request, $e);
    }

    /**
     * 渲染 API 异常
     */
    protected function renderApiException(Request $request, Throwable $e): JsonResponse
    {
        // 验证异常
        if ($e instanceof ValidationException) {
            return $this->renderValidationException($e);
        }

        // 模型未找到
        if ($e instanceof ModelNotFoundException) {
            return $this->renderModelNotFoundException($e);
        }

        // 404 未找到
        if ($e instanceof NotFoundHttpException) {
            return $this->renderNotFoundHttpException($e);
        }

        // 403 禁止访问
        if ($e instanceof AccessDeniedHttpException) {
            return $this->renderAccessDeniedHttpException($e);
        }

        // 401 未认证
        if ($e instanceof AuthenticationException) {
            return $this->renderAuthenticationException($e);
        }

        // 429 请求过多
        if ($e instanceof TooManyRequestsHttpException) {
            return $this->renderTooManyRequestsHttpException($e);
        }

        // 405 方法不允许
        if ($e instanceof MethodNotAllowedHttpException) {
            return $this->renderMethodNotAllowedHttpException($e);
        }

        // 数据库查询异常
        if ($e instanceof QueryException) {
            return $this->renderQueryException($e);
        }

        // 运行时异常（业务逻辑错误）
        if ($e instanceof \RuntimeException) {
            return $this->renderRuntimeException($e);
        }

        // 其他异常
        return $this->renderGenericException($e);
    }

    /**
     * 渲染验证异常
     */
    protected function renderValidationException(ValidationException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $e->errors(),
        ], 422);
    }

    /**
     * 渲染模型未找到异常
     */
    protected function renderModelNotFoundException(ModelNotFoundException $e): JsonResponse
    {
        $model = class_basename($e->getModel());

        return response()->json([
            'success' => false,
            'message' => "{$model} not found",
        ], 404);
    }

    /**
     * 渲染 404 异常
     */
    protected function renderNotFoundHttpException(NotFoundHttpException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Resource not found',
        ], 404);
    }

    /**
     * 渲染 403 异常
     */
    protected function renderAccessDeniedHttpException(AccessDeniedHttpException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage() ?: 'Access denied',
        ], 403);
    }

    /**
     * 渲染 401 异常
     */
    protected function renderAuthenticationException(AuthenticationException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Unauthenticated',
        ], 401);
    }

    /**
     * 渲染 429 异常
     */
    protected function renderTooManyRequestsHttpException(TooManyRequestsHttpException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Too many requests, please try again later',
        ], 429);
    }

    /**
     * 渲染 405 异常
     */
    protected function renderMethodNotAllowedHttpException(MethodNotAllowedHttpException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Method not allowed',
        ], 405);
    }

    /**
     * 渲染数据库查询异常
     */
    protected function renderQueryException(QueryException $e): JsonResponse
    {
        // 生产环境不暴露数据库错误细节
        if (app()->isProduction()) {
            return response()->json([
                'success' => false,
                'message' => 'Database error occurred',
            ], 500);
        }

        return response()->json([
            'success' => false,
            'message' => 'Database error occurred',
            'error' => $e->getMessage(),
        ], 500);
    }

    /**
     * 渲染运行时异常（业务逻辑错误）
     */
    protected function renderRuntimeException(\RuntimeException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 422);
    }

    /**
     * 渲染通用异常
     */
    protected function renderGenericException(Throwable $e): JsonResponse
    {
        $statusCode = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;

        // 生产环境不暴露错误细节
        if (app()->isProduction()) {
            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
            ], 500);
        }

        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ], $statusCode);
    }
}
