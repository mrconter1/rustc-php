// exit: 0
// stdout: 5
// stdout: 5
fn main() {
    let x: i32 = 5;
    let p1: *const i32 = &x as *const i32;
    let p2: *const i32 = &x as *const i32;
    println!("{}", *p1);
    println!("{}", *p2);
}
