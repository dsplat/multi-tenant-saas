@extends('layouts.tenant')

@section('title', 'Storage')

@section('content')
<div class="container mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold mb-6">Storage</h1>
    
    <div class="bg-white rounded-lg shadow p-6">
        <div id="tenant-content">
            <!-- Vue.js 组件挂载点 -->
            <tenant-storage></tenant-storage>
        </div>
    </div>
</div>

@push('scripts')
<script>
    // Vue.js 组件注册
    app.component('tenant-storage', {{
        template: `<div>Loading...</div>`,
        data() {{
            return {{ items: [], loading: true }}
        }},
        mounted() {{
            this.fetchData()
        }},
        methods: {{
            async fetchData() {{
                try {{
                    const res = await axios.get('/api/v1/tenant/storage')
                    this.items = res.data.data
                }} catch (err) {{
                    console.error(err)
                }} finally {{
                    this.loading = false
                }}
            }}
        }}
    }})
</script>
@endpush
@endsection
