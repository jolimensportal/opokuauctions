import { Module } from "@medusajs/framework/utils"
import LotsModuleService from "./service"

export const LOTS_MODULE = "lots"

export default Module(LOTS_MODULE, {
  service: LotsModuleService,
})
