@extends('admin.layouts.app')

@section('content')
    {{-- 官网诊断 / AI 解读（本地扩展）：内容由 GeoAuditReport 渲染为片段，嵌在后台布局内 --}}
    {!! $toolHtml !!}
@endsection
