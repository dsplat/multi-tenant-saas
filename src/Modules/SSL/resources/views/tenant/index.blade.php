@extends('layouts.tenant')

@section('title', 'SSL')

@section('content')
<div class="container mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold mb-6">SSL</h1>
    
    <div class="bg-white rounded-lg shadow p-6">
        <div id="tenant-content">
            <!-- Vue.js 组件挂载点 -->
            <tenant-s-s-l></tenant-s-s-l>
        </div>
    </div>
</div>

@push('scripts')
<script>
    // Vue.js 组件注册
    app.component('tenant-s-s-l', {{
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
                    const res = await axios.get('/api/v1/tenant/ssl')
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
