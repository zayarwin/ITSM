<script setup>
</script>

<template>
  <div class="h-full flex flex-col items-center justify-center">
    <div class="bg-slate-900 rounded-lg shadow-xl w-full max-w-4xl h-[600px] flex flex-col overflow-hidden border border-slate-700">
      <div class="bg-slate-800 p-3 border-b border-slate-700 flex justify-between items-center text-slate-300 font-mono text-sm">
        <div class="flex gap-2 items-center">
          <div class="w-3 h-3 rounded-full bg-red-500"></div>
          <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
          <div class="w-3 h-3 rounded-full bg-green-500"></div>
          <span class="ml-4">Network CLI Terminal - Not Connected</span>
        </div>
      </div>
      <div class="flex-1 p-4 font-mono text-green-400 overflow-y-auto">
        ITSM Web CLI Engine v1.0<br>
        Waiting for device connection...<br><br>
        <span class="animate-pulse">_</span>
      </div>
    </div>
  </div>
</template>
