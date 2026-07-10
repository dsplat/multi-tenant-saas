<?php

namespace MultiTenantSaas;

use OpenApi\Attributes as OA;

/**
 * @OA\Info(
 *     version="1.0.0",
 *     title="Multi-Tenant SaaS Framework API",
 *     description="多租户 SaaS 框架 API 文档

7. 默认租户",
 *     @OA\Contact(
 *         name="API Support",
 *         email="support@example.com"
 *     ),
 *
 *     @OA\License(
 *         name="MIT",
 *         url="https://opensource.org/licenses/MIT"
 *     )
 * )
 *
 * @OA\Server(
 *     url="/api/v1",
 *     description="API Server"
 * )
 *
 * @OA\Components(
 *
 *     @OA\SecurityScheme(
 *         securityScheme="sanctum",
 *         type="apiKey",
 *         name="Authorization",
 *         in="header",
 *         description="Bearer Token 认证"
 *     ),
 *
 *     @OA\Schema(
 *         schema="SuccessResponse",
 *
 *         @OA\Property(property="success", type="boolean", example=true),
 *         @OA\Property(property="message", type="string", example="操作成功"),
 *         @OA\Property(property="data", type="object")
 *     ),
 *
 *     @OA\Schema(
 *         schema="ErrorResponse",
 *
 *         @OA\Property(property="success", type="boolean", example=false),
 *         @OA\Property(property="message", type="string", example="操作失败"),
 *         @OA\Property(property="error", type="string")
 *     ),
 *
 *     @OA\Schema(
 *         schema="ValidationError",
 *
 *         @OA\Property(property="success", type="boolean", example=false),
 *         @OA\Property(property="message", type="string"),
 *         @OA\Property(property="errors", type="object")
 *     )
 * )
 */
class SwaggerInfo
{
    // 此类仅用于 Swagger 注解，无实际逻辑
}
