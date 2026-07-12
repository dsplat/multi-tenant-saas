@extends('layouts.admin')

@section('title', 'Monitoring 管理')

@section('content')
<div class="container mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold mb-6">Monitoring 管理</h1>
    
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-lg font-semibold">列表</h2>
            <button class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                新增
            </button>
        </div>
        
        <div id="data-table">
            <!-- DataTable 组件 -->
            <x-admin.data-table :endpoint="'/api/v1/admin/monitoring'" />
        </div>
    </div>
</div>
@endsection
