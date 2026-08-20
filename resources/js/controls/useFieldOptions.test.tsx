import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { describe, expect, it, vi } from 'vitest'
import type { FieldPayload } from '../graph/types'
import { FieldOptionsContext, fieldOptionsKey, useFieldOptions } from './useFieldOptions'

const TEMPLATE = '/flows/12/nodes/__NODEFLOW_TYPE__/fields/__NODEFLOW_FIELD__/options'
function field(overrides: Partial<FieldPayload> = {}): FieldPayload {
    return { key:'template', type:'select', label:'Template', help:null, default:null, required:false, options:{}, dynamic_options:false, ...overrides }
}
function wrapper(cache = new Map<string, Record<string, string>>()) {
    return ({children}:{children:ReactNode}) => <FieldOptionsContext.Provider value={{template:TEMPLATE,cache}}>{children}</FieldOptionsContext.Provider>
}

describe('useFieldOptions', () => {
    // String concat key ambiguous. Counterfactual `${nodeType} ${field.key}` cross-wires tenant-scoped options.
    it('encodes the node type and field key as an unambiguous tuple', () => {
        expect(fieldOptionsKey('a b','c')).not.toBe(fieldOptionsKey('a','b c'))
        expect(fieldOptionsKey('app.send','template')).not.toBe(fieldOptionsKey('app.send','channel'))
    })
    // Lazy per field. Counterfactual fetch static fields makes 404 requests.
    it('does not fetch for a field whose options are static', () => {
        const fetchMock=vi.fn(); vi.stubGlobal('fetch',fetchMock)
        const {result}=renderHook(()=>useFieldOptions('app.send',field({options:{a:'A'}})),{wrapper:wrapper()})
        expect(fetchMock).not.toHaveBeenCalled()
        expect(result.current.options).toEqual({a:'A'})
        expect(result.current.loading).toBe(false)
    })
    // Counterfactual read data instead of data.options -> empty list.
    it('fetches once for a dynamic field and unwraps the options key', async () => {
        vi.stubGlobal('fetch',vi.fn().mockResolvedValue(Response.json({options:{t1:'Welcome'}})))
        const {result}=renderHook(()=>useFieldOptions('app.send',field({dynamic_options:true})),{wrapper:wrapper()})
        await waitFor(()=>expect(result.current.loading).toBe(false))
        expect(result.current.options).toEqual({t1:'Welcome'})
        expect(result.current.error).toBeNull()
    })
    // Counterfactual drop cache -> refetch on every node click.
    it('serves a second field of the same type and key from the cache', async () => {
        const fetchMock=vi.fn().mockResolvedValue(Response.json({options:{t1:'Welcome'}})); vi.stubGlobal('fetch',fetchMock)
        const cache=new Map<string,Record<string,string>>()
        const first=renderHook(()=>useFieldOptions('app.send',field({dynamic_options:true})),{wrapper:wrapper(cache)})
        await waitFor(()=>expect(first.result.current.loading).toBe(false))
        const second=renderHook(()=>useFieldOptions('app.send',field({dynamic_options:true})),{wrapper:wrapper(cache)})
        expect(second.result.current.options).toEqual({t1:'Welcome'})
        expect(second.result.current.loading).toBe(false)
        expect(fetchMock).toHaveBeenCalledTimes(1)
    })
    // Hook can stay mounted when node type changes. Counterfactual init state once -> previous pair flashes.
    it('does not expose the previous pair when rerendered onto another cached pair', () => {
        const fetchMock=vi.fn(); vi.stubGlobal('fetch',fetchMock)
        const cache=new Map<string,Record<string,string>>([
          [fieldOptionsKey('app.first','template'),{old:'Old'}],
          [fieldOptionsKey('app.second','template'),{current:'Current'}],
        ])
        const {result,rerender}=renderHook(({nodeType})=>useFieldOptions(nodeType,field({dynamic_options:true})),{initialProps:{nodeType:'app.first'},wrapper:wrapper(cache)})
        expect(result.current.options).toEqual({old:'Old'})
        rerender({nodeType:'app.second'})
        expect(result.current.options).toEqual({current:'Current'})
        expect(result.current.loading).toBe(false)
        expect(result.current.error).toBeNull()
        expect(fetchMock).not.toHaveBeenCalled()
    })
    // Counterfactual obsolete request updates state -> slow old response overwrites current choices.
    it('ignores a stale response after the node type and field pair changes', async () => {
        const pending=new Map<string,(response:Response)=>void>()
        vi.stubGlobal('fetch',vi.fn((url:string|URL|Request)=>new Promise<Response>((resolve)=>pending.set(String(url),resolve))))
        const {result,rerender}=renderHook(({nodeType})=>useFieldOptions(nodeType,field({dynamic_options:true})),{initialProps:{nodeType:'app.first'},wrapper:wrapper()})
        rerender({nodeType:'app.second'})
        await act(async()=>{pending.get('/flows/12/nodes/app.second/fields/template/options')!(Response.json({options:{current:'Current'}}))})
        await waitFor(()=>expect(result.current.options).toEqual({current:'Current'}))
        await act(async()=>{pending.get('/flows/12/nodes/app.first/fields/template/options')!(Response.json({options:{stale:'Stale'}}))})
        expect(result.current.options).toEqual({current:'Current'})
    })
    // Named error, never indistinguishable empty select. Counterfactual swallow failure and return {}.
    it('reports a failure as a named error rather than an empty list', async () => {
        vi.stubGlobal('fetch',vi.fn().mockResolvedValue(Response.json({message:'Nope'},{status:500})))
        const {result}=renderHook(()=>useFieldOptions('app.send',field({dynamic_options:true})),{wrapper:wrapper()})
        await waitFor(()=>expect(result.current.loading).toBe(false))
        expect(result.current.error).toContain('500')
        expect(result.current.options).toEqual({})
    })
    // optionsUrl throws before send. Counterfactual outside guarded path -> effect throws.
    it('reports a malformed URL template as a named field error', async () => {
        const Broken=({children}:{children:ReactNode})=><FieldOptionsContext.Provider value={{template:'/no/sentinels',cache:new Map()}}>{children}</FieldOptionsContext.Provider>
        const {result}=renderHook(()=>useFieldOptions('app.send',field({dynamic_options:true})),{wrapper:Broken})
        await waitFor(()=>expect(result.current.loading).toBe(false))
        expect(result.current.error).toContain('__NODEFLOW_TYPE__')
        expect(result.current.options).toEqual({})
    })
    // Dynamic without provider is wiring defect. Counterfactual silently return {} -> looks legitimate empty.
    it('names a missing options provider rather than pretending the list is empty', () => {
        const {result}=renderHook(()=>useFieldOptions('app.send',field({dynamic_options:true})))
        expect(result.current.loading).toBe(false)
        expect(result.current.options).toEqual({})
        expect(result.current.error).toContain('FieldOptionsContext')
    })
})
