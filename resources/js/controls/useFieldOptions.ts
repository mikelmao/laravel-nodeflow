import { createContext, useContext, useEffect, useState } from 'react'
import type { FieldPayload } from '../graph/types'
import { optionsUrl, send } from '../http'

/**
 * Dynamic option source and per-editor cache. Module-global cache is forbidden:
 * SSR editors/tenants could share answers and tests become order-dependent.
 */
export type FieldOptionsSource = {
    /** urls.options from edit props, sentinels intact. */
    template: string
    cache: Map<string, Record<string, string>>
}
export const FieldOptionsContext = createContext<FieldOptionsSource | null>(null)
const EMPTY: Record<string, string> = {}
type State = { key:string; options:Record<string,string>|null; loading:boolean; error:string|null }

/** Injective cache key for node-type/field-key tuple. */
export function fieldOptionsKey(nodeType:string, fieldKey:string):string {
    return JSON.stringify([nodeType,fieldKey])
}

/**
 * Fetch only when the field is dynamic. Return named failure beside field rather
 * than empty success. Cache is per context/editor; stale requests cannot update.
 */
export function useFieldOptions(nodeType:string, field:FieldPayload):{options:Record<string,string>;loading:boolean;error:string|null} {
    const source=useContext(FieldOptionsContext)
    const key=fieldOptionsKey(nodeType,field.key)
    const cached=source?.cache.get(key)
    const [state,setState]=useState<State>(()=>({
        key,
        options:cached??null,
        loading:field.dynamic_options && source!==null && cached===undefined,
        error:field.dynamic_options && source===null ? 'Could not load the choices for this field: no FieldOptionsContext provider is mounted.' : null,
    }))

    useEffect(()=>{
        if (!field.dynamic_options) return
        let live=true
        if (!source) {
            setState({key,options:null,loading:false,error:'Could not load the choices for this field: no FieldOptionsContext provider is mounted.'})
            return
        }
        const existing=source.cache.get(key)
        if (existing!==undefined) {
            setState({key,options:existing,loading:false,error:null})
            return
        }
        setState({key,options:null,loading:true,error:null})
        let url:string
        try { url=optionsUrl(source.template,nodeType,field.key) }
        catch (reason:unknown) {
            setState({key,options:null,loading:false,error:`Could not load the choices for this field: ${String(reason)}`})
            return
        }
        send('GET',url)
          .then((result)=>{
            if(!live)return
            if(!result.ok){
              setState({key,options:null,loading:false,error:`Could not load the choices for this field (HTTP ${result.status}). The node type or field key may not be registered, or its option source may not implement Nodeflow\\Schema\\OptionSource.`})
              return
            }
            const options=(result.data?.options??{}) as Record<string,string>
            source.cache.set(key,options)
            setState({key,options,loading:false,error:null})
          })
          .catch((reason:unknown)=>{
            if(live)setState({key,options:null,loading:false,error:`Could not load the choices for this field: ${String(reason)}`})
          })
        return()=>{live=false}
    },[source,key,nodeType,field.key,field.dynamic_options])

    if(!field.dynamic_options)return {options:field.options,loading:false,error:null}
    if(state.key!==key){
      return {
        options:cached??EMPTY,
        loading:source!==null&&cached===undefined,
        error:source===null?'Could not load the choices for this field: no FieldOptionsContext provider is mounted.':null,
      }
    }
    return {options:state.options??cached??EMPTY,loading:state.loading,error:state.error}
}
